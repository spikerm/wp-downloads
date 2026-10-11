<?php
define('ABSPATH',__DIR__);
function is_wp_error($value){return false;}
function wp_get_object_terms($id,$taxonomy,$args){return $id===3 ? [] : [2];}
function wp_nonce_url($url,$nonce){return $url.'&_wpnonce=test';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function admin_url($path){return 'https://example.test/'.$path;}
function get_post_meta($id,$key,$single){return $key==='_rex_name' ? 'handboek.pdf' : ($key==='_rex_size'?4096:'');}
function size_format($size){return '4 KB';}
function esc_url($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_attr($value){return htmlspecialchars($value,ENT_QUOTES);}
function get_option($key,$default=[]){return $default;}
function get_user_meta(...$args){return [];}
function get_current_user_id(){return 1;}
function absint($value){return abs((int)$value);}
function wp_date($format,$time){return date($format,$time);}
require __DIR__.'/../rex-downloads/features.php';
require __DIR__.'/../rex-downloads/frontend.php';
$rows=[];
foreach ([[1,0,'Handleidingen'],[2,1,'Daikin'],[3,2,'VRF'],[4,0,'Lege categorie']] as $term){$rows[]=['term'=>(object)['term_id'=>$term[0],'parent'=>$term[1],'name'=>$term[2]]];}
$posts=[];
foreach ([[1,'Installatiehandleiding <R290>'],[2,'GEHEIM'],[3,'Algemeen handboek']] as $post){$posts[]=(object)['ID'=>$post[0],'post_title'=>$post[1],'post_content'=>'Documentatie'];}
ob_start();Rex_Downloads_Frontend::files($rows,$posts,function($id){return $id!==2;});$html=ob_get_clean();
foreach (['Handleidingen','Daikin','VRF','Lege categorie','Overige bestanden','&lt;R290&gt;'] as $text){if(strpos($html,$text)===false){throw new Exception('Missing: '.$text);}}
if(strpos($html,'GEHEIM')!==false || strpos($html,'id=2')!==false){throw new Exception('Unauthorized file leaked');}
if(substr_count($html,'<details')!==substr_count($html,'</details>')){throw new Exception('Unbalanced folders');}
ob_start();Rex_Downloads_Features::toolbar($rows);$toolbar=ob_get_clean();
if(in_array('--preview',$argv,true)){file_put_contents('/tmp/rex-frontend-preview.html','<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Downloads</title><body style="background:#eef1f5;padding:24px"><main style="max-width:960px;margin:auto"><section class="rex-downloads" data-ajax-url="https://example.test/wp-admin/admin-ajax.php" data-favorite-nonce="test" style="--rex-accent:#17639e">'.Rex_Downloads_Frontend::styles().Rex_Downloads_Features::styles().'<h2>Downloads</h2><p class="rex-account">Ingelogd als Rex · Uitloggen</p>'.$toolbar.$html.'</section></main></body></html>');}
echo "Frontend nesting and access filtering passed.\n";
