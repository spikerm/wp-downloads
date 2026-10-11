<?php
define('ABSPATH',__DIR__); define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600);
function plugin_basename($file){return 'rex-downloads/rex-downloads.php';}
function add_action(...$args){} function add_filter(...$args){}
$cache=false; $requests=0; $status=200;
$body=['tag_name'=>'v1.4.0','assets'=>[['name'=>'rex-downloads.zip','browser_download_url'=>'https://github.com/spikerm/wp-downloads/releases/download/v1.4.0/rex-downloads.zip']]];
function get_site_transient($key){return $GLOBALS['cache'];}
function set_site_transient($key,$value,$ttl){$GLOBALS['cache']=$value;}
function wp_remote_get($url,$args){$GLOBALS['requests']++; return ['code'=>$GLOBALS['status'],'body'=>json_encode($GLOBALS['body'])];}
function is_wp_error($value){return false;}
function wp_remote_retrieve_response_code($value){return $value['code'];}
function wp_remote_retrieve_body($value){return $value['body'];}
function esc_url_raw($value){return $value;}
function sanitize_textarea_field($value){return strip_tags($value);}
function sanitize_text_field($value){return strip_tags($value);}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES);}
function verify($condition,$message){if(!$condition){throw new Exception($message);}}
require __DIR__.'/../rex-downloads/github-updater.php';
$updater=new Rex_Downloads_GitHub_Updater('rex-downloads.php','1.3.0');
verify($updater->release()['version']==='1.4.0','Version parsing');
$updater->release(); verify($requests===1,'Cached release must avoid second request');
$update=$updater->update(false,['UpdateURI'=>Rex_Downloads_GitHub_Updater::URI],'rex-downloads/rex-downloads.php',[]);
verify($update['version']==='1.4.0','WordPress update metadata');
verify($updater->update(false,[],'another/plugin.php',[])===false,'Other plugins unaffected');
verify($updater->information(false,'plugin_information',(object)['slug'=>'other'])===false,'Other plugin information unaffected');
$cache=false;$body['prerelease']=true;verify($updater->release()===false,'Ignore prereleases');
$cache=false;$body['prerelease']=false;$body['assets'][0]['browser_download_url']='https://example.com/rex-downloads.zip';verify($updater->release()===false,'Reject unexpected package host');
$cache=false;$body['assets']=[];verify($updater->release()===false,'No source ZIP fallback');
$cache=false;$status=403;verify($updater->release()===false,'Handle API rate limit');
$before=$requests;$updater->release();verify($requests===$before,'Backoff after failure');
echo "Updater tests passed.\n";
