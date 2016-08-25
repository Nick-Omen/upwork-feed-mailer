<?php

require_once('./mailer.php');

if (!$configs['query']) return;

function start_watching()
{
    global $mysqli, $configs;

    $results = $mysqli->query("SELECT `value` FROM `config` WHERE `key`='cron_active'");
    $cronActive = false;
    if (gettype($results) == 'object' && $results->fetch_row()[0] == '1') $cronActive = true;
    if (!$cronActive) return;

    $url = "https://www.upwork.com/ab/feed/jobs/atom?contractor_tier=1%2C2&q={$configs['query']}&sort=create_time+desc&api_params=1";
    $curl_handle=curl_init();
    curl_setopt($curl_handle, CURLOPT_URL, $url);
    curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);

    // Get works as object
    $xmlUpworkJobs = curl_exec($curl_handle);
    curl_close($curl_handle);
    
    if(!$xmlUpworkJobs) {
        $xmlUpworkJobs = file_get_contents($url);    
    }

    $upworkJobs = simplexml_load_string($xmlUpworkJobs);
    // Parse upwork jobs
    $parsedUpworkJobs = parse_jobs_from_upwork($upworkJobs);
    if (count($parsedUpworkJobs) > 0) {
        // Latest date
        $latestDate = get_latest_date($parsedUpworkJobs);
        $viewedJobsIds = get_viewed_jobs_by_date($latestDate);
        $notViewedJobs = filter_unviewed_jobs($viewedJobsIds, $parsedUpworkJobs);
        insert_new_jobs($notViewedJobs);
        $jobsSendIds = send_email($notViewedJobs);
        if (strlen($jobsSendIds) > 1) {
            set_jobs_status_send($jobsSendIds);
        }
    }
    $now = new DateTime();
    $date = "{$now->date} {$now->timezone}";
    $mysqli->query("UPDATE `config` SET `value` ='{$date}' WHERE `key`='latest_update'");
    sleep((int)$configs['sleep_seconds']);
    start_watching();
}

// Functions

/**
 * Send email with new jobs.
 */
function send_email($jobs)
{
    global $mail;

    $str = '';
    if (count($jobs) == 0) return '';

    $mailBody = '<table width="100%">';
    foreach ($jobs as $id => $j) {
        $s = isset($j['parsed_summary']['Skills']) ? $j['parsed_summary']['Skills'] : ' - ';
        $b = isset($j['parsed_summary']['Budget']) ? $j['parsed_summary']['Budget'] : ' - ';
        $mailBody .= "<tr><th width='50%' align='left'>{$j['title']}<th><td width='32%' align='left'>{$s}</td><td width='10%' align='right'>{$b}</td><td width='8%' align='right'><a target='_blank' href='{$j['link']}'>link</a></td></tr>";
        $str .= (string)$id.',';
    }
    $mailBody .= '</table>';
    $mail->Body = $mailBody;

    if($mail->send()) return substr($str, 0, -1);

    return '';
}

/**
 * Find and get job ID from link.
 * @param $link - string
 * @return job id - string
 */
function get_job_id($link)
{
    preg_match('/(\~|%)(?P<id>[\w]+)\?/', $link, $m);
    return $m['id'];
}

/**
 * Parse summary of the requested body.
 * @param $summary - string
 * @param $key
 * @return job summary - array
 */
function parse_summary($summary)
{
    $filters = ['Budget','Posted','Category','Skills','Country'];
    $array = explode("<br />", $summary);
    $array =  array_filter($array);

    $final_array = array();

    foreach ($array as $aKey => $aValue) {
        foreach ($filters as $fKey => $fValue) {
            if (strpos($aValue, $fValue) !== false) {
                $final_array[$fValue] = str_replace(array($fValue,":","<b>","</b>"), '', $aValue);
            }
        }
    }
    return $final_array;
}

/**
 * Parse summary of the requested body.
 * @param $jobs - object
 * @return parsed jobs - array
 */
function parse_jobs_from_upwork($jobs)
{
    if (gettype($jobs) != 'object') trigger_error('Cant read jobs.');
    $parsedJobs = array();
    for ($i=0; $i < count($jobs->entry); $i++) {
        $jobId = get_job_id((string)$jobs->entry[$i]->id);
        $parsedJobs[$jobId]['title'] = (string)$jobs->entry[$i]->title;
        $parsedJobs[$jobId]['link'] = (string)$jobs->entry[$i]->link->attributes()->href;
        $parsedJobs[$jobId]['content'] = (string)$jobs->entry[$i]->content;
        $parsedJobs[$jobId]['updated'] = (string)$jobs->entry[$i]->updated;
        $summary = (string)$jobs->entry[$i]->summary;
        $parsedJobs[$jobId]['parsed_summary'] = parse_summary($summary);
    }
    return $parsedJobs;
}

function insert_new_jobs($jobs)
{
    global $mysqli;

    $sqlInsertNewJobs = "INSERT IGNORE INTO `viewed_jobs`(`job_id`, `date`) VALUES ";
    foreach ($jobs as $k => $v)
    {
        $sqlInsertNewJobs .= "('{$k}', '{$v['updated']}'),";
    }
    $sqlInsertNewJobs = substr($sqlInsertNewJobs, 0, -1);

    return $results = $mysqli->query($sqlInsertNewJobs);
}

/**
 * Get latest date from array of jobs.
 * @param $jobs - array
 * @return latest date - string
 */
function get_latest_date($jobs)
{
    $lDate = new DateTime();
    foreach ($jobs as $j) {
        $tmpDateTime = new DateTime($j['updated']);
        if ($tmpDateTime < $lDate) {
            $lDate = $tmpDateTime;
        }
    }
    return $lDate->format($lDate::ATOM);
}

/**
 * Get most recent date to get request from base.
 * @param $date - string
 * @return ids oj jobs - array
 */
function get_viewed_jobs_by_date($date)
{
    global $mysqli;

    $sql = "SELECT `job_id` FROM `viewed_jobs` WHERE `date` >='{$date}' AND `send`=0";
    $jobIds = array();
    if ($results = $mysqli->query($sql)) {
        foreach ($results as $r) {
            $jobIds[] = $r['job_id'];
        }
    }
    return $jobIds;
}

/**
 * Filter unviewed jobs by ids from db
 * @param $ids - array
 * @param $jobs - array
 * @return unviewed jobs - array
 */
function filter_unviewed_jobs($ids, $jobs)
{
    foreach($jobs as $k => $v) {
        if (in_array((string)$k, $ids)) unset($jobs[$k]);
    }
    return $jobs;
}

function set_jobs_status_send($ids)
{
    global $mysqli;

    if (gettype($ids) != 'string') return false;

    $sql = "UPDATE `viewed_jobs` SET `send` = 1 WHERE `job_id` IN ({$ids})";
    return $mysqli->query($sql);
}
