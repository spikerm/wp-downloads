<?php
define('ABSPATH',__DIR__);
class WP_Error {public function __construct(...$args){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_post_type($id){return 'rex_download';}
function get_post_status($id){return $GLOBALS['status'];}
function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$key]??'';}
function get_user_meta($id,$key,$single){return $key==='rex_download_grants'?$GLOBALS['grants']:[];}
function absint($v){return abs((int)$v);}
function get_option($key,$default){return [2=>[9=>0,3=>1]];}
function wp_timezone(){return new DateTimeZone('Europe/Amsterdam');}
function user_can($user,$cap){return in_array('administrator',$user->roles,true);}
require __DIR__.'/../rex-downloads/workflow.php';
function expect($value,$label){if(!$value){throw new Exception($label);}}
$status='publish';$meta=['_rex_publish_at'=>100,'_rex_expire_at'=>200];$grants=[];
expect(!Rex_Downloads_Workflow::available(1,99),'before start');expect(Rex_Downloads_Workflow::available(1,100),'inclusive start');expect(!Rex_Downloads_Workflow::available(1,200),'exclusive expiry');
$status='rex_trash';expect(!Rex_Downloads_Workflow::available(1,150),'trash blocked');$status='publish';
expect(is_wp_error(Rex_Downloads_Workflow::parse_date('2026-02-30T12:00')),'invalid date');expect(is_wp_error(Rex_Downloads_Workflow::parse_date('2026-03-29T02:30')),'nonexistent local time');
expect(Rex_Downloads_Workflow::parse_date('2026-01-01T12:00')===strtotime('2026-01-01 11:00 UTC'),'site timezone');
$meta=['_rex_roles'=>['technician']];$user=(object)['ID'=>5,'roles'=>['subscriber']];expect(!Rex_Downloads_Workflow::allowed_for(1,$user),'restricted subscriber');$grants=[1];expect(Rex_Downloads_Workflow::allowed_for(1,$user),'approved grant');$meta['_rex_expire_at']=1;expect(!Rex_Downloads_Workflow::allowed_for(1,$user),'grant cannot bypass schedule');
$posts=[];foreach([3,7,9] as $id){$posts[]=(object)['ID'=>$id,'post_title'=>'File '.$id];}$sorted=Rex_Downloads_Workflow::ordered_files($posts,2);expect(array_column($sorted,'ID')===[9,3,7],'category order');
echo "Publication boundaries, timezone, grants and file order passed.\n";
