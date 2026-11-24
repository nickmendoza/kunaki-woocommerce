<?php
// Runs inside WP-CLI in the disposable container. Args via env SCEN.
function t($n,$ok,$x=''){ echo ($ok?'PASS':'FAIL')." [".(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'legacy')."] $n".($ok?'':"  -- ".print_r($x,true))."\n"; }
function mk($pid,$vid=0,$status=null){ $o=wc_create_order(); $p=wc_get_product($vid?:$pid); $o->add_product($p,1);
 $o->set_shipping_first_name('A');$o->set_shipping_last_name('B');$o->set_shipping_address_1('1 St');$o->set_shipping_city('NYC');$o->set_shipping_state('NY');$o->set_shipping_postcode('10001');$o->set_shipping_country('US');
 $s=new WC_Order_Item_Shipping(); $s->set_method_id('kunaki_shipping'); $s->set_method_title('USPS'); $s->add_meta_data('kunaki_shipping_description','USPS First Class Mail'); $o->add_item($s); $o->save(); if($status){$o->set_status($status);$o->save();} return $o->get_id(); }
function run_queue(){ $n=0; foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','group'=>'kunaki-woocommerce','status'=>'pending','per_page'=>-1],'ids') as $id){ ActionScheduler::runner()->process_action($id,'stage'); $n++; } return $n; }
function log_has($s){ foreach(get_option('mock_kunaki_log',[]) as $b) if(strpos($b,$s)!==false) return true; return false; }
$ids=get_option('stage_ids');
switch(getenv('SCEN')){
case 'queue':
 update_option('mock_kunaki_mode','ok'); delete_option('kunaki_wc_circuit_breaker'); delete_option('mock_kunaki_log');
 $t0=microtime(true); $id=mk($ids['disc'],0,'processing'); 
 t('no Kunaki call happens during the status change', !log_has('<Order>'), get_option('mock_kunaki_log'));
 t('submission is queued in Action Scheduler', (bool)as_next_scheduled_action('kunaki_wc_submit_order',['order_id'=>$id],'kunaki-woocommerce'));
 run_queue(); $o=wc_get_order($id);
 t('queued job submits order to Kunaki', 'yes'===$o->get_meta('_kunaki_submitted') && $o->get_meta('_kunaki_order_id'), $o->get_meta_data());
 t('address hash stored', ''!==$o->get_meta('_kunaki_address_hash'));
 t('Country sent as Kunaki spelling', log_has('<Country>United States</Country>'));
 $o->set_status('cancelled'); $o->save(); $notes=wc_get_order_notes(['order_id'=>$id]); t('cancel after submission adds warning note', (bool)array_filter($notes,fn($n)=>stripos($n->content,'kunaki')!==false&&stripos($n->content,$o->get_meta('_kunaki_order_id'))!==false));
 delete_option('mock_kunaki_log'); $id2=mk($ids['disc'],0,'processing'); $o2=wc_get_order($id2); $o2->set_status('cancelled'); $o2->save(); run_queue();
 t('order cancelled while queued is NOT sent', !log_has('<Order>'), get_option('mock_kunaki_log'));
 break;
case 'variation':
 delete_option('mock_kunaki_log'); $o=mk($ids['album'],$ids['var']['DVD'],'processing'); run_queue();
 t('variation submits its own Kunaki ID', log_has('PXVARDVD01') && !log_has('PXVARCD001'), get_option('mock_kunaki_log'));
 delete_option('mock_kunaki_log'); $o=mk($ids['album'],$ids['var']['Sticker'],'processing'); run_queue();
 t('variation without ID (and parent without ID) is not sent', !log_has('<Order>'));
 update_post_meta($ids['album'],'_kunaki_product_id','PXPARENT01'); delete_option('mock_kunaki_log'); $o=mk($ids['album'],$ids['var']['Sticker'],'processing'); run_queue();
 t('variation without ID inherits parent ID', log_has('PXPARENT01'), get_option('mock_kunaki_log')); delete_post_meta($ids['album'],'_kunaki_product_id');
 break;
case 'park':
 delete_option('mock_kunaki_log'); $id=mk($ids['disc'],0,'processing'); update_option('stage_parked',$id);
 $sub=mk($ids['disc'],0,'processing'); run_queue();
 $id=mk($ids['disc'],0,'processing'); update_option('stage_parked',$id); echo "queued $id\n";
 break;
case 'park2':
 $id=get_option('stage_parked'); $o=wc_get_order($id);
 t('deactivation records failure on queued order', ''!==(string)$o->get_meta('_kunaki_fulfillment_error'), $o->get_meta_data());
 t('deactivation does not use a retry attempt', ''===(string)$o->get_meta('_kunaki_fulfillment_attempts'));
 global $wpdb; $tb=$wpdb->prefix.'actionscheduler_actions'; $st=$wpdb->get_col($wpdb->prepare("SELECT status FROM $tb WHERE hook='kunaki_wc_submit_order' AND args=%s",wp_json_encode(['order_id'=>(int)$id]))); t('no pending job left behind', $st && !array_intersect(['pending','in-progress'],$st), $st);
 break;
case 'park3':
 $id=get_option('stage_parked'); do_action('kunaki_wc_retry_failed_orders'); $o=wc_get_order($id);
 t('order sent by retry cron after reactivation', 'yes'===$o->get_meta('_kunaki_submitted'), $o->get_meta_data());
 t('failure cleared after retry', ''===(string)$o->get_meta('_kunaki_fulfillment_error'));
 break;
case 'sync':
 foreach(wc_get_orders(['status'=>'processing','limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 delete_option('kunaki_wc_circuit_breaker'); update_option('mock_kunaki_status','PROCESSING');
 $mk=function($st,$note=false) use($ids){ $id=mk($ids['disc']); $o=wc_get_order($id); $o->set_status('processing'); $o->save(); foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $a) ActionScheduler::store()->cancel_action($a); $o=wc_get_order($id); $o->update_meta_data('_kunaki_order_id','K'.$id); $o->update_meta_data('_kunaki_submitted','yes'); if($st) $o->update_meta_data('_kunaki_order_status',$st); if($note) $o->update_meta_data('_kunaki_sync_note_pending',wp_json_encode([['text'=>'n','is_customer'=>false]])); $o->save(); return $id; };
 $new=$mk(''); $proc=$mk('PENDING'); $ship=$mk('SHIPPED'); $note=$mk('SHIPPED',true);
 $s=new Kunaki_Order_Status_Sync(); $p=$s->get_pending_order_ids();
 t('order with no status yet is polled', in_array($new,$p)); t('in-progress order is polled', in_array($proc,$p));
 t('shipped order excluded', !in_array($ship,$p),$p); t('shipped order with pending note included', in_array($note,$p),$p);
 sort($p); $e=[$new,$proc,$note]; sort($e); t('nothing else in the list', $p===$e, compact('p','e'));
 delete_option('mock_kunaki_log'); $r=$s->sync_all_pending_orders();
 t('return counts: 3 checked, 2 changed (new + PENDING)', $r===['checked'=>3,'updated'=>2],$r);
 $sh=0; foreach(get_option('mock_kunaki_log',[]) as $b) if(strpos($b,'K'.$ship)!==false) $sh++; t('no API call for the shipped order',0===$sh);
 break;
case 'queries':
 foreach(wc_get_orders(['status'=>['processing','on-hold'],'limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 delete_option('kunaki_wc_circuit_breaker'); delete_transient('kunaki_dashboard_counts'); update_option('mock_kunaki_mode','ok');
 $mk=function($status,$meta) use($ids){ $id=mk($ids['disc']); $o=wc_get_order($id); $o->set_status($status); $o->save(); foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $a) ActionScheduler::store()->cancel_action($a); $o=wc_get_order($id); foreach($meta as $k=>$v) $o->update_meta_data($k,$v); $o->save(); return $id; };
 $idle=$mk('processing',[]); $sub=$mk('processing',['_kunaki_submitted'=>'yes','_kunaki_order_id'=>'K1','_kunaki_order_status'=>'PROCESSING']); $shipped=$mk('processing',['_kunaki_submitted'=>'yes','_kunaki_order_id'=>'K2','_kunaki_order_status'=>'SHIPPED']);
 $err=$mk('processing',['_kunaki_fulfillment_error'=>'boom','_kunaki_fulfillment_attempts'=>0]); $ex=$mk('processing',['_kunaki_fulfillment_exhausted'=>'yes','_kunaki_fulfillment_last_error'=>'gone']); $dec=$mk('on-hold',['_kunaki_submitted'=>'yes','_kunaki_order_id'=>'K3','_kunaki_order_status'=>'DECLINED']);
 $w=new Kunaki_Dashboard_Widget(); $r=new ReflectionMethod($w,'query_counts'); if(PHP_VERSION_ID<80100)$r->setAccessible(true); $c=$r->invoke($w);
 t('dashboard counts exact', $c===['fulfilling'=>1,'action_needed'=>2,'retrying'=>1,'ready_to_complete'=>1], $c);
 $f=array_map(fn($o)=>$o->get_id(),(new Kunaki_Retry_Queue())->get_failed_orders()); sort($f); $e=[$err,$ex,$dec]; sort($e); t('failures page lists exactly the failed orders',$f===$e,compact('f','e'));
 delete_option('mock_kunaki_log'); do_action('kunaki_wc_retry_failed_orders'); $o=wc_get_order($idle); t('retry cron ignores a healthy order',''===(string)$o->get_meta('_kunaki_submitted'),$o->get_meta_data());
 $o=wc_get_order($err); t('retry cron submits the failed order','yes'===$o->get_meta('_kunaki_submitted'),$o->get_meta_data());
 // Estimator: old store with many unrelated orders must still find shipped ones.
 $st=new Kunaki_Delivery_Estimator(); $m=new ReflectionMethod($st,'compute_manufacturing_stats'); if(PHP_VERSION_ID<80100)$m->setAccessible(true); for($i=0;$i<Kunaki_Delivery_Estimator::MIN_SAMPLE_SIZE;$i++){ $mk('completed',['_kunaki_submitted_at'=>gmdate('c',time()-3*DAY_IN_SECONDS),'_kunaki_shipped_at'=>gmdate('c',time()-DAY_IN_SECONDS)]); } $v=$m->invoke($st); t('estimator computes from shipped orders',is_array($v)&&$v['sample_size']>=Kunaki_Delivery_Estimator::MIN_SAMPLE_SIZE,$v);
 break;
case 'misc':
 t('TR -> Turkey', 'Turkey'===Kunaki_API_Client::get_country_name('TR')); t('VA -> Vatican City','Vatican City'===Kunaki_API_Client::get_country_name('VA'));
 t('GB strips code','United Kingdom'===Kunaki_API_Client::get_country_name('GB')); t('US strips code','United States'===Kunaki_API_Client::get_country_name('US'));
 // Kunaki's list, verbatim from https://kunaki.com/XMLService.htm. Every supported code must be sent with one of these names.
 $kunaki=['Australia','Austria','Belgium','Bulgaria','Canada','China','Cyprus','Czech Republic','Denmark','Estonia','Finland','France','Germany','Gibraltar','Greenland','Hong Kong','Hungary','Iceland','Ireland','Italy','Japan','Latvia','Liechtenstein','Lithuania','Luxembourg','Mexico','Netherlands','New Zealand','Norway','Poland','Portugal','Romania','Singapore','Slovakia','Slovenia','Spain','Sweden','Switzerland','Taiwan','Turkey','Ukraine','United Kingdom','United States','Vatican City','Yugoslavia'];
 $bad=[]; $sent=[]; foreach(Kunaki_API_Client::SUPPORTED_COUNTRIES as $cc){ $nm=Kunaki_API_Client::get_country_name($cc); $sent[]=$nm; if(!in_array($nm,$kunaki,true)) $bad[$cc]=$nm; }
 t('every supported country is sent with a name on Kunaki\'s list', !$bad, $bad);
 t('supported countries cover all of Kunaki\'s list except Yugoslavia', array_values(array_diff($kunaki,$sent))===['Yugoslavia'], array_diff($kunaki,$sent));
 t('password encrypted at rest', false===strpos($GLOBALS['wpdb']->get_var("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name='kunaki_wc_password'"),'S3cret'));
 // Real DB compare-and-swap.
 $st=wp_json_encode(['state'=>'open','failure_count'=>5,'last_failure_at'=>time()-999,'opened_at'=>time()-999]); update_option('kunaki_wc_circuit_breaker',$st,false);
 $a=new Kunaki_Circuit_Breaker(); $x=$a->pre_request(); $y=$a->pre_request(); t('first caller probes, second is blocked', true===$x && is_wp_error($y), [$x,$y]);
 $a->post_request(simplexml_load_string('<R/>')); t('successful probe closes circuit','closed'===$a->get_state()['state']);
 update_option('kunaki_wc_circuit_breaker',$st,false); foreach(wc_get_orders(['status'=>['processing'],'limit'=>-1,'return'=>'ids']) as $zz) wc_get_order($zz)->update_status('cancelled'); $o=mk($ids['disc']); $w=wc_get_order($o); $w->set_status('processing'); $w->save(); foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $q) ActionScheduler::store()->cancel_action($q); $w=wc_get_order($o); $w->update_meta_data('_kunaki_order_id','K1');$w->update_meta_data('_kunaki_submitted','yes'); $w->save(); update_option('mock_kunaki_mode','ok');
 (new Kunaki_Order_Status_Sync())->sync_all_pending_orders(); t('sync cron probes and closes circuit after cooldown (no checkout traffic)','closed'===$a->get_state()['state'],$a->get_state());
 $c=\Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature('cart_checkout_blocks'); echo 'blocks-compat: '.wp_json_encode($c)."\n";
 break;
}
