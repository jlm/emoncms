<?php

    $mqttsettings = array(
        'userid' => 1,
        'basetopic' => "rx"
    );

    /*
    
    MQTT input interface script, subscribes to topic emoncms/input
    
    Topics:

    emoncms/input/10            100,200,300
    emoncms/input/10/1          100
    emoncms/input/10/power      250
    emoncms/input/house/power   2500
    
    * userid has to be set in script, no method of setting timestamp
    
    Emoncms then processes these inputs in the same way as they would be
    if sent to the HTTP Api.
    
    */

    // This code is released under the GNU Affero General Public License.
    // OpenEnergyMonitor project:
    // http://openenergymonitor.org
    
    define('EMONCMS_EXEC', 1);

    $fp = fopen("/var/lock/phpmqtt_input.lock", "w");
    if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }
    
    chdir(dirname(__FILE__)."/../");
    require "Lib/EmonLogger.php";
    require "process_settings.php";
    
    if (!$mqtt_enabled) { echo "Error: setting must be true: mqtt_enabled\n"; die; }
    if (!isset($mqtt_server)) { echo "Error: mqtt server not configured, check setting: mqtt_server\n"; die; }
    
    $log = new EmonLogger(__FILE__);
    $log->info("Starting MQTT Input script");
    
    $mysqli = @new mysqli($server,$username,$password,$database);
    $redis = new Redis();
    $redis->connect($redis_server['host'], $redis_server['port']);
    $redis->setOption(Redis::OPT_PREFIX, $redis_server['prefix'].':');
    
    require("Lib/phpMQTT.php");
    $mqtt = new phpMQTT($mqtt_server, 1883, "Emoncms input subscriber");
    
    require("Modules/user/user_model.php");
    $user = new User($mysqli,$redis,null);

    require_once "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis, $feed_settings);

    require_once "Modules/input/input_model.php";
    $input = new Input($mysqli,$redis, $feed);

    require_once "Modules/process/process_model.php";
    $this->process = new Process($mysqli,$input,$feed,$user->get_timezone($mqttsettings['userid']));
  
    if(!$mqtt->connect()){
        echo "Can't connect to MQTT.\n"; exit(1);
    }


    $topic = $mqttsettings['basetopic']."/#";
    echo "Subscribing to: ".$topic."\n";
    
    $topics[$topic] = array("qos"=>0, "function"=>"procmsg");
    $mqtt->subscribe($topics,0);
    while($mqtt->proc()){ }
    $mqtt->close();
    
    function procmsg($topic,$value)
    { 
        $time = time();
        echo $topic." ".$value."\n";
        
        global $mqttsettings, $user, $input, $process, $feed;
        
        $userid = $mqttsettings['userid'];
        
        $inputs = array();
        
        $route = explode("/",$topic);

        if ($route[0]==$mqttsettings['basetopic'])
        {
            // nodeid defined in topic:  emoncms/input/10
            if (isset($route[1]))
            {
                $nodeid = $route[1];
                $dbinputs = $input->get_inputs($userid);
            
                // input id defined in topic:  emoncms/input/10/1
                if (isset($route[2]))
                {
                    $inputs[] = array("userid"=>$userid, "time"=>$time, "nodeid"=>$nodeid, "name"=>$route[2], "value"=>$value);
                }
                else 
                {
                    $values = explode(",",$value);
                    $name = 0;
                    foreach ($values as $value) {
                        $inputs[] = array("userid"=>$userid, "time"=>$time, "nodeid"=>$nodeid, "name"=>$name++, "value"=>$value);
                    }
                }
            }
        }
        
        $tmp = array();
        foreach ($inputs as $i)
        {
            $userid = $i['userid'];
            $time = $i['time'];
            $nodeid = $i['nodeid'];
            $name = $i['name'];
            $value = $i['value'];
            
            if (!isset($dbinputs[$nodeid][$name])) {
                $inputid = $input->create_input($userid, $nodeid, $name);
                $dbinputs[$nodeid][$name] = true;
                $dbinputs[$nodeid][$name] = array('id'=>$inputid);
                $input->set_timevalue($dbinputs[$nodeid][$name]['id'],$time,$value);
                echo "set_timevalue\n";
            } else {
                $inputid = $dbinputs[$nodeid][$name]['id'];
                $input->set_timevalue($dbinputs[$nodeid][$name]['id'],$time,$value);
                
                if ($dbinputs[$nodeid][$name]['processList']) $tmp[] = array('value'=>$value,'processList'=>$dbinputs[$nodeid][$name]['processList']);
            }
        }
        
        foreach ($tmp as $i) $process->input($time,$i['value'],$i['processList']);
      
    }
