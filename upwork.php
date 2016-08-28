<?php

require_once('./mailer.php');

if (!$configs['query']) return;


function start_watching()
{
    $GLOBALS['configs'] = get_configs();
    
    global $mysqli, $configs;

    $cronActive = false;
    $results = $mysqli->query("SELECT `value` FROM `config` WHERE `key`='cron_active'");
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

    if ($parsedUpworkJobs) {
        // Latest date
        $latestDate = get_latest_date($parsedUpworkJobs);
        $viewedJobsIds = get_viewed_jobs_by_date($latestDate);
        $notViewedJobs = filter_unviewed_jobs($viewedJobsIds, $parsedUpworkJobs);
        if ($notViewedJobs) {
            insert_new_jobs($notViewedJobs);
            $jobsSendIds = send_email($notViewedJobs);
            if ($jobsSendIds) {
                set_jobs_status_send($jobsSendIds);
            }
        }
    }
    $now = new DateTime();
    $date = date(DATE_ATOM, $now->getTimestamp());
    $mysqli->query("UPDATE `config` SET `value` ='{$date}' WHERE `key`='latest_update'");
    sleep((int)$configs['sleep_seconds']);
    start_watching();
}

// Functions

/**
 * Send email with new jobs.
 * @param $jobs
 * @return string
 * @throws phpmailerException
 */
function send_email($jobs)
{
    if(gettype($jobs) != 'array' && count($jobs) == 0) {
        error_log('No jobs to send in mail.');
        return '';
    }

    global $mail;

    $str = '';
    if (count($jobs) == 0) return '';

    $mailBody = '<table width="100%">';
    foreach ($jobs as $id => $j) {
        $s = isset($j['parsed_summary']['Skills']) ? $j['parsed_summary']['Skills'] : ' - ';
        $b = isset($j['parsed_summary']['Budget']) ? $j['parsed_summary']['Budget'] : ' - ';
        $d = isset($j['updated']) ? $j['updated'] : ' - ';
        $mailBody .= "<tr><th width='40%' align='left'>{$j['title']}<th><td width='27%' align='left'>{$s}</td><td width='15%' align='left'>{$d}</td><td width='10%' align='right'>{$b}</td><td width='8%' align='right'><a target='_blank' href='{$j['link']}'>link</a></td></tr>";
        $str .= "'".(string)$id."',";
    }
    $mailBody .= '</table>';
    $mail->Body = $mailBody;
    $str = substr($str, 0, -1);

    if($mail->send()) return $str;

    return '';
}

/**
 * Find and get job ID from link.
 * @param $link
 * @return string
 */
function get_job_id($link)
{
    preg_match('/(\~|%)(?P<id>[\w]+)\?/', $link, $m);
    return $m['id'];
}

/**
 * Parse summary of the requested body.
 * @param $summary
 * @return array
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
 * @param $jobs
 * @return array
 */
function parse_jobs_from_upwork($jobs)
{
    $parsedJobs = array();

    if (gettype($jobs) != 'object' || count($jobs) == 0) {
        error_log('No jobs from XML was parsed.');
        return $parsedJobs;
    };

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

/**
 * Insert new jobs in db.
 * @param $jobs
 * @return bool|mysqli_result
 */
function insert_new_jobs($jobs)
{
    if(gettype($jobs) != 'array' && count($jobs) == 0) {
        error_log('No jobs to insert');
        return false;
    }
    global $mysqli;

    $sqlInsertNewJobs = "INSERT IGNORE INTO `viewed_jobs`(`job_id`, `date`) VALUES ";
    foreach ($jobs as $k => $v)
    {
        $sqlInsertNewJobs .= "('{$k}', '{$v['updated']}'),";
    }
    $sqlInsertNewJobs = substr($sqlInsertNewJobs, 0, -1);

    return $mysqli->query($sqlInsertNewJobs);
}

/**
 * Get latest date from array of jobs.
 * @param $jobs
 * @return string
 */
function get_latest_date($jobs)
{
    if(gettype($jobs) != 'array' || count($jobs) == 0) {
        error_log('Jobs are not an array or jobs count is 0.');
        return '';
    }
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
 * Request jobs from db by date.
 * @param $date
 * @return array
 */
function get_viewed_jobs_by_date($date)
{
    $jobIds = array();
    if (gettype($date) != 'string' || strlen($date) == 0) {
        error_log('Date is not a string or its empty.');
        return $jobIds;
    }
    global $mysqli;

    $sql = "SELECT `job_id` FROM `viewed_jobs` WHERE `date` >='{$date}' AND `send`=0";
    if ($results = $mysqli->query($sql)) {
        foreach ($results as $r) {
            $jobIds[] = $r['job_id'];
        }
    }
    return $jobIds;
}

/**
 * Filter unviewed jobs by ids from db
 * @param $ids
 * @param $jobs
 * @return array
 */
function filter_unviewed_jobs($ids, $jobs)
{
    if(gettype($ids) != 'array' || gettype($jobs) != 'array' || count($jobs) == 0) {
        error_log('No jobs to filter.');
        return array();
    }
    foreach($jobs as $k => $v) {
        if (in_array((string)$k, $ids)) unset($jobs[$k]);
    }
    return $jobs;
}

/**
 * Set job status to send.
 * @param $ids
 * @return bool|mysqli_result
 */
function set_jobs_status_send($ids)
{
    if (gettype($ids) != 'string' || strlen($ids) == 0) {
        error_log('No ids to update');
        return false;
    }
    global $mysqli;

    $sql = "UPDATE `viewed_jobs` SET `send` = 1 WHERE `job_id` IN ({$ids})";
    $currentCount = $mysqli->query("SELECT COUNT(`job_id`) FROM `viewed_jobs`");
    $mysqli->query("UPDATE `config` SET `value`='{$currentCount->fetch_array()[0]}' WHERE `key`='total'");

    return $mysqli->query($sql);
}
