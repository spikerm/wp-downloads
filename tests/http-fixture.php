<?php
// Local integration fixture only; this file is never included in the plugin ZIP.
$site=($_GET['site']??'A')==='B'?'B':'A';
$fixture=getenv('REX_TEST_DIR')?:sys_get_temp_dir().'/rex-http-tests';
@mkdir($fixture.'/'.$site.'/web',0700,true);@mkdir($fixture.'/'.$site.'/private',0700,true);@mkdir($fixture.'/'.$site.'/web/uploads',0700,true);
define('ABSPATH',$fixture.'/'.$site.'/web/');define('REX_DOWNLOADS_DIR',$fixture.'/'.$site.'/private');
define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
$_SERVER['DOCUMENT_ROOT']=ABSPATH;
$statefile=$fixture.'/'.$site.'/state.json';
$state=is_file($statefile)?json_decode(file_get_contents($statefile),true):['posts'=>[],'meta'=>[],'terms'=>[],'termmeta'=>[],'relations'=>[],'options'=>['rex_download_db_version'=>'1'],'users'=>[],'events'=>[]];
register_shutdown_function(function()use($statefile){file_put_contents($statefile,json_encode($GLOBALS['state']));});
if($site==='A'&&!$state['posts']){
 $state['posts'][1]=['ID'=>1,'post_type'=>'rex_download','post_status'=>'publish','post_title'=>'Private PDF','post_content'=>'Technical manual','post_date_gmt'=>'2020-01-01 00:00:00'];
 $state['terms'][1]=['term_id'=>1,'name'=>'Manuals','parent'=>0,'count'=>1];$state['relations'][1]=[1];$state['termmeta'][1]=['_rex_access_mode'=>'custom','_rex_roles'=>['rex_tech']];
 $key=str_repeat('a',48).'.bin';$content="%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";file_put_contents(REX_DOWNLOADS_DIR.'/'.$key,$content);
 $state['meta'][1]=['_rex_name'=>'private.pdf','_rex_key'=>$key,'_rex_size'=>strlen($content),'_rex_roles'=>['rex_tech'],'_rex_access_mode'=>'inherit','_rex_uuid'=>'a1234567-1234-1234-1234-123456789012','_rex_version'=>'1.0.0','_rex_changelog'=>'Initial'];
 foreach([101=>'manual.pdf',102=>'firmware.zip'] as $id=>$name){file_put_contents(ABSPATH.'uploads/'.$name,$id===101?$content:'Firmware');$state['posts'][$id]=['ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>$name,'post_content'=>'','file'=>ABSPATH.'uploads/'.$name];}
}
class WP_Error{private $message;public function __construct($key,$message){$this->message=$message;}public function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
$hooks=[];
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name][$priority][]=[$callback,$args];}
function do_action($name,...$args){$hooks=$GLOBALS['hooks'][$name]??[];ksort($hooks);foreach($hooks as $callbacks){foreach($callbacks as $entry){call_user_func_array($entry[0],array_slice($args,0,$entry[1]));}}}
function add_filter(...$args){}function add_shortcode(...$args){}
function plugin_basename($file){return 'rex-downloads/rex-downloads.php';}
function is_user_logged_in(){return ($_GET['auth']??'admin')!=='none';}
function current_user_can($cap,...$args){$auth=$_GET['auth']??'admin';return $auth==='admin'||($cap==='read'&&$auth==='tech');}
function wp_get_current_user(){return (object)['ID'=>is_user_logged_in()?1:0,'display_name'=>'Test user','roles'=>[($_GET['auth']??'admin')==='tech'?'rex_tech':'subscriber']];}
function get_current_user_id(){return is_user_logged_in()?1:0;}
function wp_die($message,$title='',$args=[]){http_response_code($args['response']??400);echo $message;exit;}
function auth_redirect(){http_response_code(401);exit;}
function check_admin_referer($action){if(($_REQUEST['_wpnonce']??'')!=='valid'){wp_die('Invalid nonce','',['response'=>403]);}}
function check_ajax_referer($action,$field){if(($_POST[$field]??'')!=='valid'){wp_die('Invalid nonce','',['response'=>403]);}}
function wp_create_nonce($action){return 'valid';}
function wp_nonce_field($action,$name='_wpnonce'){echo '<input type="hidden" name="'.$name.'" value="valid">';}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=valid';}
function wp_unslash($value){return $value;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function sanitize_file_name($v){return basename($v);}function absint($v){return abs((int)$v);}
function sanitize_hex_color($v){return preg_match('/^#[a-f0-9]{6}$/i',(string)$v)?$v:null;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}function esc_url_raw($v){return $v;}function esc_textarea($v){return esc_html($v);}
function checked($a,$b=true,$echo=true){$value=$a==$b?'checked':'';if($echo){echo $value;}return $value;}function selected($a,$b,$echo=true){$value=$a==$b?'selected':'';if($echo){echo $value;}return $value;}
function get_post($id){return isset($GLOBALS['state']['posts'][$id])?(object)$GLOBALS['state']['posts'][$id]:null;}
function get_post_type($id){return get_post($id)->post_type??null;}function get_post_status($id){return get_post($id)->post_status??null;}
function get_post_meta($id,$key,$single){return $GLOBALS['state']['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['state']['meta'][$id][$key]=$value;return true;}
function wp_update_post($args,$error=false){return wp_insert_post($args,$error);}
function wp_delete_post($id,$force=false){unset($GLOBALS['state']['posts'][$id],$GLOBALS['state']['meta'][$id],$GLOBALS['state']['relations'][$id]);return true;}
function wp_timezone(){return new DateTimeZone('UTC');}
function set_transient($key,$value,$ttl){update_option($key,$value);}
function get_transient($key){return get_option($key);}
function delete_transient($key){unset($GLOBALS['state']['options'][$key]);}
function wp_schedule_single_event($time,$hook,$args){$GLOBALS['state']['scheduled'][]=[$time,$hook,$args];}
function get_term_children($id,$taxonomy){$out=[];foreach($GLOBALS['state']['terms'] as $term){if($term['parent']===$id){$out[]=$term['term_id'];$out=array_merge($out,get_term_children($term['term_id'],$taxonomy));}}return $out;}
function get_ancestors($id,...$args){$out=[];while($id&&($id=$GLOBALS['state']['terms'][$id]['parent']??0)){$out[]=$id;}return $out;}
function get_userdata($id){return (object)['ID'=>$id,'display_name'=>'Subscriber','user_email'=>'subscriber@example.test','roles'=>['subscriber']];}
function user_can($user,$cap){return in_array('administrator',(array)$user->roles,true);}
function get_users($args){return array_map('get_userdata',array_slice(array_keys($GLOBALS['state']['users']),$args['offset'],$args['number']));}
function home_url($path){return 'https://example.test'.$path;}
function wp_mail($email,$subject,$body){$GLOBALS['state']['mails'][]=[$email,$subject,$body];return true;}
function wp_insert_post($args,$error=false){$id=$args['ID']??0;if(!$id){$id=max(array_keys($GLOBALS['state']['posts'])?:[100])+1;}$GLOBALS['state']['posts'][$id]=array_merge($GLOBALS['state']['posts'][$id]??[],['post_date_gmt'=>gmdate('Y-m-d H:i:s')],$args,['ID'=>$id]);return $id;}
function get_posts($args){$out=[];foreach($GLOBALS['state']['posts'] as $id=>$p){if($p['post_type']!==($args['post_type']??'post')){continue;}if(isset($args['post_status'])&&$args['post_status']!=='any'&&!in_array($p['post_status'],(array)$args['post_status'],true)){continue;}if(isset($args['meta_key'])&&get_post_meta($id,$args['meta_key'],true)!==$args['meta_value']){continue;}if(isset($args['author'])&&($p['post_author']??0)!==$args['author']){continue;}if(isset($args['tax_query'])&&!array_intersect($args['tax_query'][0]['terms'],$GLOBALS['state']['relations'][$id]??[])){continue;}$out[]=(object)$p;}return $out;}
function get_terms($args){return array_values(array_map(function($term){return (object)$term;},array_filter($GLOBALS['state']['terms'],function($term)use($args){return !isset($args['parent'])||$term['parent']===$args['parent'];})));}
function get_term($id,$taxonomy){return isset($GLOBALS['state']['terms'][$id])?(object)$GLOBALS['state']['terms'][$id]:null;}
function get_term_meta($id,$key,$single){return $GLOBALS['state']['termmeta'][$id][$key]??'';}function update_term_meta($id,$key,$value){$GLOBALS['state']['termmeta'][$id][$key]=$value;}
function term_exists($value,$taxonomy,$parent=0){foreach($GLOBALS['state']['terms'] as $term){if((is_int($value)&&$term['term_id']===$value)||($term['name']===$value&&$term['parent']===$parent)){return ['term_id'=>$term['term_id']];}}return null;}
function wp_update_term($id,$taxonomy,$args){$GLOBALS['state']['terms'][$id]=array_merge($GLOBALS['state']['terms'][$id],$args);return ['term_id'=>$id];}
function wp_insert_term($name,$taxonomy,$args){$id=max(array_keys($GLOBALS['state']['terms'])?:[0])+1;$GLOBALS['state']['terms'][$id]=['term_id'=>$id,'name'=>$name,'parent'=>$args['parent']??0,'count'=>0];return ['term_id'=>$id];}
function wp_set_object_terms($id,$terms,$taxonomy){$GLOBALS['state']['relations'][$id]=$terms;return $terms;}
function wp_get_object_terms($id,$taxonomy,$args){return $GLOBALS['state']['relations'][$id]??[];}
function get_option($key,$default=false){return $GLOBALS['state']['options'][$key]??$default;}function update_option($key,$value,...$args){$GLOBALS['state']['options'][$key]=$value;return true;}
function wp_roles(){return new class{public $roles=['subscriber'=>[],'rex_tech'=>[]];public function get_names(){return ['subscriber'=>'Subscriber','rex_tech'=>'Technicians'];}};}
function get_role($key){return array_key_exists($key,wp_roles()->roles)?(object)[]:null;}function add_role($key,$label,$caps){return (object)[];}
function get_user_meta($id,$key,$single){return $GLOBALS['state']['users'][$id][$key]??'';}function update_user_meta($id,$key,$value){$GLOBALS['state']['users'][$id][$key]=$value;}
function wp_generate_uuid4(){return 'b'.substr(bin2hex(random_bytes(4)),0,7).'-1234-1234-1234-'.bin2hex(random_bytes(6));}
function wp_upload_dir(){return ['basedir'=>ABSPATH.'uploads'];}function get_attached_file($id){return get_post($id)->file??null;}function wp_max_upload_size(){return 33554432;}
function wp_normalize_path($v){return str_replace('\\','/',$v);}function trailingslashit($v){return rtrim($v,'/').'/';}function wp_mkdir_p($v){return is_dir($v)||mkdir($v,0700,true);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function wp_date($format,$time){return gmdate($format,$time);}function size_format($size){return $size.' B';}
function admin_url($path){return '/'.$path;}function wp_safe_redirect($url){header('Location: '.$url);return true;}function nocache_headers(){header('Cache-Control: no-store');}
function wp_send_json_error($data,$status=400){http_response_code($status);header('Content-Type: application/json');echo json_encode(['success'=>false,'data'=>$data]);exit;}
function wp_send_json_success($data){header('Content-Type: application/json');echo json_encode(['success'=>true,'data'=>$data]);exit;}
class FakeDatabase{public $prefix='wp_';public function prepare($query,...$args){return $query;}public function query($query){return true;}public function get_results($query){return [];}
public function insert($table,$data,$format){$GLOBALS['state']['events'][]=$data;return 1;}}
$wpdb=new FakeDatabase();
require __DIR__.'/../rex-downloads/rex-downloads.php';
function translate_user_role($value){return $value;}
function submit_button($text){echo '<p><button type="submit">'.esc_html($text).'</button></p>';}
function wp_enqueue_script(...$args){}
function wp_enqueue_media(){}
function plugins_url($name,$file){return '/'.$name;}
function wp_login_form($args){echo '<form class="login-form"></form>';}
function wp_lostpassword_url($url){return '#password';}
function wp_logout_url($url){return '#logout';}
function get_permalink(){return '/downloads';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
$action=$_GET['test_action']??'';
if(in_array($action,['manager','dashboard','trash','requests','integrity'],true)){(new Rex_Downloads_Workflow($rex_downloads_core))->{$action}();exit;}
if($action==='admin'){ $rex_downloads_core->admin();exit; }
if($action==='frontend'){echo '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="padding:16px;background:#eef2f7"><main style="max-width:1000px;margin:auto">'.$rex_downloads_core->shortcode().'</main></body></html>';exit;}

if($action==='collision'){$state['terms'][1]=['term_id'=>1,'name'=>'Manuals','parent'=>0,'count'=>0];$state['termmeta'][1]=['_rex_access_mode'=>'custom','_rex_roles'=>['*']];echo 'ok';exit;}
if($action==='state'){header('Content-Type: application/json');echo json_encode($state);exit;}
if(in_array($action,['request_access','watch','integrity_begin','integrity_batch'],true)){do_action('wp_ajax_rex_'.$action);exit;}
if($action==='notify'){foreach($state['scheduled']??[] as $event){do_action($event[1],...$event[2]);}exit;}
if($action==='favorite'){do_action('wp_ajax_rex_favorite');exit;}
do_action((!is_user_logged_in()&&in_array($action,['download','preview'],true)?'admin_post_nopriv_rex_':'admin_post_rex_').$action);
