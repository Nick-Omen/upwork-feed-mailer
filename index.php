<?php
    require_once('./config.php');
    require_once('./db.php');
    require_once('./upwork.php');

    $cUpdated = 0;
    $cFieldsUpdated = array();

    //Get queries should use in auto-module
    if(isset($_GET['direct'])) {

        $direct = true;
        if(isset($_GET['stop_sender'])) {
            sender_status(0);
        }
        if(isset($_GET['start_sender'])) {
            sender_status(1);
        }

        if(isset($_GET['q'])) {
            $cUpdated = $mysqli -> query("UPDATE `config` SET `value`='{$_GET['q']}' WHERE `key` = 'query'");
        }

        $sender_status = $mysqli->query("SELECT `value` FROM `config` WHERE `key` = 'cron_active'");
        $sender_status = $sender_status->fetch_row()[0];
        
        if($sender_status) {
            start_watching();
        }
    }

    if ($_POST) {
        if (isset($_POST['q'])) {
            $cUpdated = $mysqli -> query("UPDATE `config` SET `value`='{$_POST['q']}' WHERE `key` = 'query'");
            $cFieldsUpdated[] = 'query';
        }
        if (isset($_POST['startParse']) && $_POST['startParse'] == 'on') {
            $cUpdated = $mysqli -> query("UPDATE `config` SET `value`='1' WHERE `key` = 'cron_active'");
            $cFieldsUpdated[] = 'cron_active';
            start_watching();
        } else {
            $cUpdated = $mysqli -> query("UPDATE `config` SET `value`='0' WHERE `key` = 'cron_active'");
            $cFieldsUpdated[] = 'cron_active';
        }
    }
    $mysqli->close();
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Upwork</title>
    </head>
    <body>
        <div style="margin-bottom: 20px;">
                <h4 style="margin-bottom: 10;">Summary: </h4>
                <ul style="margin-top: 0;">
                    <?php if ($configs['total']) { ?><li><b>Total jobs viewed:</b> <?php echo $configs['total']; ?></li><?php } ?>
                    <?php if ($configs['query']) { ?><li><b>Current query:</b> <?php echo $configs['query']; ?></li><?php } ?>
                    <?php if ($configs['cron_active']) { $status = $configs['cron_active'] == '0' ? 'Disabled' : 'Enabled'; ?><li><b>Status:</b> <?php echo $status; ?></li><?php } ?>
                    <?php if ($configs['latest_update']) { ?><li><b>Last update:</b> <?php echo $configs['latest_update']; ?></li><?php } ?>
                </ul>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="query">
            <input name="q" placeholder="Query">
            <label><input type="checkbox" name="startParse" /> Enable parse</label>
            <input type="submit">
        </form>
        <div id="output" style="margin-top: 20px;">

        </div>
        <?php if ($cUpdated) { ?> Configs was updated
            <?php if (count($cFieldsUpdated) > 0 && gettype($cFieldsUpdated) == 'array') { ?>
                <ul>
                    <?php foreach ($cFieldsUpdated as $f) { ?>
                        <li><?php echo $f; ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>
        <?php } ?>
    </body>
</html>
