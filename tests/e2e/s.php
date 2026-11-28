<?php
// Runs inside WP-CLI in the disposable container. Args via env SCEN.
function t($n,$ok,$x=''){ echo ($ok?'PASS':'FAIL')." [".(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'legacy')."] $n".($ok?'':"  -- ".print_r($x,true))."\n"; }
// $kunaki_line=false builds what an order placed with the plugin inactive looks like: another shipping method, no Kunaki choice.
function mk($pid,$vid=0,$status=null,$kunaki_line=true){ $o=wc_create_order(); $p=wc_get_product($vid?:$pid); $o->add_product($p,1);
 $o->set_shipping_first_name('A');$o->set_shipping_last_name('B');$o->set_shipping_address_1('1 St');$o->set_shipping_city('NYC');$o->set_shipping_state('NY');$o->set_shipping_postcode('10001');$o->set_shipping_country('US');
 $s=new WC_Order_Item_Shipping(); if($kunaki_line){ $s->set_method_id('kunaki_shipping'); $s->set_method_title('USPS'); $s->add_meta_data('kunaki_shipping_description','USPS First Class Mail'); } else { $s->set_method_id('flat_rate'); $s->set_method_title('Flat rate'); } $o->add_item($s); $o->save(); if($status){$o->set_status($status);$o->save();} return $o->get_id(); }
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
case 'alerts':
 // No mail server in the container: capture mail and Slack posts instead of sending them.
 $GLOBALS['mails']=[]; $GLOBALS['slack']=[]; $GLOBALS['mail_ok']=true;
 // WooCommerce's own order emails go through wp_mail too, so only count mail to the alert address.
 // Setting mail_ok to false makes every send fail, as a broken mail server would.
 // Setting it to 'throw' makes a send to the alert address throw, as a broken mail plugin would.
 add_filter('pre_wp_mail',function($r,$a){ $mine='ops@example.com'===($a['to']??''); if($mine) $GLOBALS['mails'][]=$a; if($mine && 'throw'===$GLOBALS['mail_ok']) throw new RuntimeException('mail plugin blew up'); return false!==$GLOBALS['mail_ok']; },10,2);
 add_filter('pre_http_request',function($r,$args,$url){ if(false===strpos($url,'slack')) return $r; $GLOBALS['slack'][]=$url; return ['headers'=>[],'body'=>'ok','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]]; },5,3);
 $pending=function(){ return as_get_scheduled_actions(['hook'=>'kunaki_wc_send_alert','status'=>'pending','per_page'=>-1],'ids'); };
 $run_alerts=function() use($pending){ foreach($pending() as $a) ActionScheduler::runner()->process_action($a,'stage'); };
 $reset=function(){ $GLOBALS['mails']=[]; $GLOBALS['slack']=[]; };
 $subjects=function(){ return array_map(fn($m)=>$m['subject'],$GLOBALS['mails']); };
 // An order with a disc but no Kunaki shipping line fails permanently.
 $bad_order=function() use($ids){ $o=wc_create_order(); $o->add_product(wc_get_product($ids['disc']),1); $o->set_shipping_first_name('A');$o->set_shipping_last_name('B');$o->set_shipping_address_1('1 St');$o->set_shipping_city('NYC');$o->set_shipping_state('NY');$o->set_shipping_postcode('10001');$o->set_shipping_country('US'); $o->save(); $o->set_status('processing'); $o->save(); run_queue(); return $o->get_id(); };
 foreach(wc_get_orders(['status'=>['processing'],'limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 update_option('mock_kunaki_mode','ok'); delete_option('kunaki_wc_circuit_breaker'); delete_option('kunaki_wc_alert_outage_open');
 foreach($pending() as $a) ActionScheduler::store()->cancel_action($a);

 update_option('kunaki_wc_alerts_enabled','no'); update_option('kunaki_wc_alert_email_address','ops@example.com');
 $id=$bad_order(); $run_alerts();
 t('alerts off: permanent failure sends nothing', !$GLOBALS['mails'] && !$pending(), $GLOBALS['mails']);
 t('order is marked as needing attention', 'yes'===wc_get_order($id)->get_meta('_kunaki_fulfillment_exhausted'));

 update_option('kunaki_wc_alerts_enabled','yes'); update_option('kunaki_wc_alert_email_enabled','yes'); update_option('kunaki_wc_alert_email_address','ops@example.com');
 update_option('kunaki_wc_alert_slack_enabled','yes'); update_option('kunaki_wc_alert_slack_webhook','https://hooks.slack.com/services/T0/B0/X0');
 t('Slack webhook URL is saved', 'https://hooks.slack.com/services/T0/B0/X0'===get_option('kunaki_wc_alert_slack_webhook'));

 $reset(); $id=$bad_order();
 t('alert is queued, not sent during the request', !$GLOBALS['mails'] && 1===count($pending()), $GLOBALS['mails']);
 $run_alerts(); $o=wc_get_order($id);
 t('permanent failure emails the alert address once', 1===count($GLOBALS['mails']) && 'ops@example.com'===$GLOBALS['mails'][0]['to'], $GLOBALS['mails']);
 t('email names the order and links to it', false!==strpos($GLOBALS['mails'][0]['subject']??'','#'.$o->get_order_number()) && false!==strpos($GLOBALS['mails'][0]['message']??'',$o->get_edit_order_url()), $GLOBALS['mails']);
 t('email says what went wrong', false!==strpos($GLOBALS['mails'][0]['message']??'','No matching Kunaki shipping method'), $GLOBALS['mails']);
 t('Slack gets the same alert', 1===count($GLOBALS['slack']) && 0===strpos($GLOBALS['slack'][0],'https://hooks.slack.com/'), $GLOBALS['slack']);

 // Retries exhausted: the retry cron finds an order at the limit.
 $reset(); $id=mk($ids['disc'],0,'processing'); foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $a) ActionScheduler::store()->cancel_action($a);
 $o=wc_get_order($id); $o->update_meta_data('_kunaki_fulfillment_error','Connection timed out'); $o->update_meta_data('_kunaki_fulfillment_attempts',3); $o->update_meta_data('_kunaki_fulfillment_failed_at',gmdate('c',time()-DAY_IN_SECONDS)); $o->save();
 do_action('kunaki_wc_retry_failed_orders'); $run_alerts();
 $mine=array_values(array_filter($GLOBALS['mails'],fn($m)=>false!==strpos($m['subject'],'#'.wc_get_order($id)->get_order_number())));
 t('retries exhausted sends one alert with the last error', 1===count($mine) && false!==strpos($mine[0]['message'],'Connection timed out') && false!==strpos($mine[0]['message'],'3 attempts'), $GLOBALS['mails']);
 do_action('kunaki_wc_retry_failed_orders'); $run_alerts();
 t('next retry run does not alert again', 1===count(array_filter($GLOBALS['mails'],fn($m)=>false!==strpos($m['subject'],'#'.wc_get_order($id)->get_order_number()))), $subjects());

 // Declined by Kunaki, found by status sync.
 $reset(); foreach(wc_get_orders(['status'=>['processing'],'limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 $id=mk($ids['disc'],0,'processing'); foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $a) ActionScheduler::store()->cancel_action($a);
 $o=wc_get_order($id); $o->update_meta_data('_kunaki_order_id','KDEC1'); $o->update_meta_data('_kunaki_submitted','yes'); $o->save();
 update_option('mock_kunaki_status','DECLINED'); (new Kunaki_Order_Status_Sync())->sync_all_pending_orders(); $run_alerts();
 t('declined order goes on hold', 'on-hold'===wc_get_order($id)->get_status());
 t('declined order sends one alert naming the Kunaki order', 1===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][0]['subject'],'declined') && false!==strpos($GLOBALS['mails'][0]['message'],'KDEC1'), $GLOBALS['mails']);
 update_option('mock_kunaki_status','PROCESSING');

 // Outage: one alert per outage, recovery only once it stays up.
 $reset(); $cb=new Kunaki_Circuit_Breaker(); $threshold=(int)get_option('kunaki_wc_circuit_threshold',5);
 for($i=0;$i<$threshold;$i++) $cb->post_request(new WP_Error('http_request_failed','mock outage'));
 $run_alerts();
 t('circuit trip sends one outage alert', 'open'===$cb->get_state()['state'] && 1===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][0]['subject'],'outage'), $subjects());
 update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'half_open','failure_count'=>$threshold,'last_failure_at'=>time(),'opened_at'=>time()-999]),false);
 $cb->post_request(new WP_Error('http_request_failed','still down')); $run_alerts();
 t('failed recovery check does not alert again', 1===count($GLOBALS['mails']), $subjects());
 update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'half_open','failure_count'=>$threshold,'last_failure_at'=>time(),'opened_at'=>time()-999]),false);
 $cb->post_request(simplexml_load_string('<R><ErrorCode>0</ErrorCode></R>'));
 $next=as_next_scheduled_action('kunaki_wc_send_alert');
 t('recovery is checked again in 15 minutes, not reported at once', 1===count($GLOBALS['mails']) && $next>=time()+14*MINUTE_IN_SECONDS, [$next,time()]);
 $run_alerts();
 t('recovery alert sent once the API is still up', 2===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][1]['subject'],'recovered'), $subjects());

 // An outage alert nobody received is sent again at the next failed check.
 $flag=function(){ global $wpdb; return $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='kunaki_wc_alert_outage_open'"); };
 $reset(); $GLOBALS['mail_ok']=false; update_option('kunaki_wc_alert_slack_enabled','no'); delete_option('kunaki_wc_circuit_breaker');
 for($i=0;$i<$threshold;$i++) $cb->post_request(new WP_Error('http_request_failed','mock outage'));
 t('outage is flagged while its alert is queued', null!==$flag() && 1===count($pending()), [$flag(),count($pending())]);
 $run_alerts();
 t('outage alert that could not be delivered does not count as sent', 1===count($GLOBALS['mails']) && null===$flag(), [$subjects(),$flag()]);
 $GLOBALS['mail_ok']=true; update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'half_open','failure_count'=>$threshold,'last_failure_at'=>time(),'opened_at'=>time()-999]),false);
 $cb->post_request(new WP_Error('http_request_failed','still down')); $run_alerts();
 t('next failed check sends the outage alert', 2===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][1]['subject'],'outage') && null!==$flag(), $subjects());
 delete_option('kunaki_wc_circuit_breaker'); delete_option('kunaki_wc_alert_outage_open'); update_option('kunaki_wc_alert_slack_enabled','yes');

 // Alerts turned off before the API recovers: the outage is forgotten, so the next one alerts.
 $reset(); for($i=0;$i<$threshold;$i++) $cb->post_request(new WP_Error('http_request_failed','mock outage'));
 $run_alerts(); update_option('kunaki_wc_alerts_enabled','no');
 update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'half_open','failure_count'=>$threshold,'last_failure_at'=>time(),'opened_at'=>time()-999]),false);
 $cb->post_request(simplexml_load_string('<R><ErrorCode>0</ErrorCode></R>'));
 t('recovery while alerts are off forgets the outage', 1===count($GLOBALS['mails']) && null===$flag() && !$pending(), [$subjects(),$flag(),count($pending())]);
 update_option('kunaki_wc_alerts_enabled','yes');
 for($i=0;$i<$threshold;$i++) $cb->post_request(new WP_Error('http_request_failed','mock outage'));
 $run_alerts();
 t('next outage after alerts are turned back on is alerted', 2===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][1]['subject'],'outage') && null!==$flag(), $subjects());
 delete_option('kunaki_wc_circuit_breaker'); delete_option('kunaki_wc_alert_outage_open');

 // An order alert nobody received is sent again an hour later, and given up on after three resends.
 $reset(); $GLOBALS['mail_ok']=false; update_option('kunaki_wc_alert_slack_enabled','no');
 $bad_order(); $run_alerts(); $next=as_next_scheduled_action('kunaki_wc_send_alert');
 t('order alert that could not be delivered is queued again for an hour later', 1===count($GLOBALS['mails']) && 1===count($pending()) && $next>=time()+59*MINUTE_IN_SECONDS, [$subjects(),count($pending()),$next,time()]);
 $GLOBALS['mail_ok']=true; $run_alerts();
 t('the resend delivers the order alert', 2===count($GLOBALS['mails']) && !$pending(), [$subjects(),count($pending())]);
 $reset(); $GLOBALS['mail_ok']=false; $bad_order(); for($i=0;$i<8;$i++) $run_alerts();
 t('order alert is given up on after three resends', 4===count($GLOBALS['mails']) && !$pending(), [count($GLOBALS['mails']),count($pending())]);
 // An order cancelled before its alert is resent has nothing left to retry, so the resend is dropped.
 $reset(); $id=$bad_order(); $run_alerts(); $GLOBALS['mail_ok']=true; wc_get_order($id)->update_status('cancelled');
 t('cancelled order still carries its failure', 'yes'===wc_get_order($id)->get_meta('_kunaki_fulfillment_exhausted') && 1===count($pending()), [wc_get_order($id)->get_meta('_kunaki_fulfillment_exhausted'),count($pending())]);
 $run_alerts();
 t('order cancelled before the resend is not alerted', 1===count($GLOBALS['mails']) && !$pending(), [$subjects(),count($pending())]);
 // A send that throws counts as not delivered too: the alert is queued again, and the other channel is still tried.
 $reset(); $GLOBALS['mail_ok']='throw'; $bad_order(); $run_alerts();
 t('order alert whose email throws is queued again', 1===count($GLOBALS['mails']) && 1===count($pending()), [$subjects(),count($pending())]);
 foreach($pending() as $a) ActionScheduler::store()->cancel_action($a);
 update_option('kunaki_wc_alert_slack_enabled','yes');
 $reset(); $bad_order(); $run_alerts();
 t('email that throws does not stop Slack', 1===count($GLOBALS['mails']) && 1===count($GLOBALS['slack']) && !$pending(), [$subjects(),$GLOBALS['slack'],count($pending())]);
 $GLOBALS['mail_ok']=true;

 // The saved password can't be decrypted, as after a change of salts: one alert, and orders wait for it to be entered again.
 $reset(); global $wpdb; $wpdb->update($wpdb->options,['option_value'=>base64_encode(random_bytes(48))],['option_name'=>'kunaki_wc_password']); wp_cache_flush();
 new Kunaki_API_Client(); // Any use of the API reads the password, a shopper's shipping rates included.
 t('unreadable password is flagged and its alert queued, not sent during the request', 'yes'===get_option('kunaki_wc_decrypt_failed') && !$GLOBALS['mails'] && 1===count($pending()), [get_option('kunaki_wc_decrypt_failed'),$subjects(),count($pending())]);
 $run_alerts();
 t('unreadable password sends one alert that links to the settings', 1===count($GLOBALS['mails']) && false!==strpos($GLOBALS['mails'][0]['subject'],'password') && false!==strpos($GLOBALS['mails'][0]['message'],'tab=kunaki') && 1===count($GLOBALS['slack']), [$GLOBALS['mails'],$GLOBALS['slack']]);
 $id=mk($ids['disc'],0,'processing'); run_queue(); new Kunaki_API_Client(); $run_alerts(); $o=wc_get_order($id);
 t('orders wait without using up retries, and nothing alerts again', 1===count($GLOBALS['mails']) && !$pending() && ''!==(string)$o->get_meta('_kunaki_fulfillment_error') && ''===(string)$o->get_meta('_kunaki_fulfillment_attempts') && 'yes'!==$o->get_meta('_kunaki_fulfillment_exhausted'), [$subjects(),count($pending()),$o->get_meta('_kunaki_fulfillment_error'),$o->get_meta('_kunaki_fulfillment_attempts')]);
 update_option('kunaki_wc_password','S3cret-Stage-Pw!'); do_action('kunaki_wc_retry_failed_orders');
 t('the waiting order is sent once the password is saved again', 'yes'===wc_get_order($id)->get_meta('_kunaki_submitted') && 'yes'!==get_option('kunaki_wc_decrypt_failed'), [wc_get_order($id)->get_meta('_kunaki_fulfillment_error'),get_option('kunaki_wc_decrypt_failed')]);

 update_option('kunaki_wc_alert_slack_webhook','https://evil.example/hook');
 t('non-Slack webhook URL is not saved', ''===get_option('kunaki_wc_alert_slack_webhook'), get_option('kunaki_wc_alert_slack_webhook'));
 delete_option('kunaki_wc_alert_slack_webhook'); add_option('kunaki_wc_alert_slack_webhook','http://hooks.slack.com/services/T0/B0/X0');
 t('add_option is checked too, and plain http is refused', ''===get_option('kunaki_wc_alert_slack_webhook'), get_option('kunaki_wc_alert_slack_webhook'));

 // Leave an outage flagged and its recovery check queued, with alerts off, so deactivation can be checked to clear both.
 $reset(); for($i=0;$i<$threshold;$i++) $cb->post_request(new WP_Error('http_request_failed','mock outage'));
 $run_alerts(); update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'half_open','failure_count'=>$threshold,'last_failure_at'=>time(),'opened_at'=>time()-999]),false);
 $cb->post_request(simplexml_load_string('<R><ErrorCode>0</ErrorCode></R>'));
 update_option('kunaki_wc_alerts_enabled','no'); update_option('kunaki_wc_alert_slack_enabled','no');
 t('a flagged outage and a queued alert are left for the deactivation check', 1===count($GLOBALS['mails']) && null!==$flag() && 1===count($pending()), [$subjects(),$flag(),count($pending())]);
 break;
case 'listener':
 // Another plugin's listener throws on the plugin's public actions. That must not undo what the plugin just did.
 foreach(wc_get_orders(['status'=>['processing'],'limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 $cancel_queued=function(){ foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $a) ActionScheduler::store()->cancel_action($a); };
 $cancel_queued(); update_option('mock_kunaki_mode','ok'); delete_option('mock_kunaki_log');
 add_action('kunaki_wc_circuit_breaker_closed',function(){ throw new RuntimeException('listener blew up'); },1);
 add_action('kunaki_wc_order_needs_attention',function(){ throw new RuntimeException('listener blew up'); },1);
 // The submission is the request that closes the circuit, so the listener throws just after Kunaki accepts the order.
 update_option('kunaki_wc_circuit_breaker',wp_json_encode(['state'=>'open','failure_count'=>5,'last_failure_at'=>time()-999,'opened_at'=>time()-999]),false);
 $id=mk($ids['disc'],0,'processing'); run_queue(); $o=wc_get_order($id);
 t('order accepted while a breaker listener throws is recorded as submitted', 'yes'===$o->get_meta('_kunaki_submitted') && 0===strpos((string)$o->get_meta('_kunaki_order_id'),'KUN') && ''===(string)$o->get_meta('_kunaki_fulfillment_error'), $o->get_meta_data());
 t('circuit closed by that submission', 'closed'===(new Kunaki_Circuit_Breaker())->get_state()['state'] && log_has('<Order>'));
 // Retry run with two orders out of retries: the listener throws on the first.
 $spent=function() use($ids,$cancel_queued){ $id=mk($ids['disc'],0,'processing'); $cancel_queued(); $o=wc_get_order($id); $o->update_meta_data('_kunaki_fulfillment_error','Connection timed out'); $o->update_meta_data('_kunaki_fulfillment_attempts',(int)get_option('kunaki_wc_max_retries',3)); $o->update_meta_data('_kunaki_fulfillment_failed_at',gmdate('c',time()-DAY_IN_SECONDS)); $o->save(); return $id; };
 $a=$spent(); $b=$spent(); $threw='';
 try { do_action('kunaki_wc_retry_failed_orders'); } catch(Throwable $e){ $threw=$e->getMessage(); }
 t('retry run finishes when an order listener throws', ''===$threw && 'yes'===wc_get_order($a)->get_meta('_kunaki_fulfillment_exhausted') && 'yes'===wc_get_order($b)->get_meta('_kunaki_fulfillment_exhausted'), [$threw,wc_get_order($a)->get_meta('_kunaki_fulfillment_exhausted'),wc_get_order($b)->get_meta('_kunaki_fulfillment_exhausted')]);
 break;
case 'catchup1':
 // The plugin is deactivated while this runs, so no plugin class may be used here.
 t('catch-up: the plugin is inactive while these orders are paid', !class_exists('Kunaki_Order_Fulfillment'));
 foreach(wc_get_orders(['status'=>['processing'],'limit'=>-1,'return'=>'ids']) as $x) wc_get_order($x)->update_status('cancelled');
 update_option('mock_kunaki_mode','ok'); delete_option('kunaki_wc_circuit_breaker'); delete_option('mock_kunaki_two_rates'); delete_option('kunaki_wc_catch_up_notice');
 // Backdate the start of the gap so the older orders below fall inside it.
 update_option('kunaki_wc_catch_up',['from'=>time()-60*DAY_IN_SECONDS],false);
 $paid=function($id,$ago){ $o=wc_get_order($id); $o->set_date_paid(time()-$ago); $o->save(); return $id; };
 $c=[];
 $c['a']=mk($ids['disc'],0,'processing');                               // paid now, Kunaki shipping from checkout
 $c['b']=$paid(mk($ids['disc'],0,'processing'),3*DAY_IN_SECONDS);       // paid 3 days ago
 $c['c']=$paid(mk($ids['disc'],0,'processing'),40*DAY_IN_SECONDS);      // older than the scan looks
 $c['d']=mk($ids['album'],$ids['var']['Sticker'],'processing');         // no discs
 $c['e']=mk($ids['disc'],0,'processing',false);                         // paid now, placed while inactive: no Kunaki shipping
 $c['f']=mk($ids['disc'],0,'processing',false);
 // Paid now with Kunaki shipping, so the scan would send these. catchup2 gives each a reason to be left alone.
 $c['h']=mk($ids['disc'],0,'processing'); $c['i']=mk($ids['disc'],0,'processing'); $c['j']=mk($ids['disc'],0,'processing');
 // What 1.4.0 left on an order with no Kunaki shipping line: failed for good, never told what Retry would do.
 $c['g']=mk($ids['disc'],0,'processing',false); $o=wc_get_order($c['g']); $o->update_meta_data('_kunaki_fulfillment_exhausted','yes'); $o->update_meta_data('_kunaki_fulfillment_last_error','No matching Kunaki shipping method found on this order.'); $o->save();
 update_option('stage_catchup',$c,false); delete_option('mock_kunaki_log');
 $a=wc_get_order($c['a']);
 t('catch-up: an order paid while inactive gets a paid date', (bool)$a->get_date_paid());
 t('catch-up: an order paid while inactive has no Kunaki record and nothing queued', ''===(string)$a->get_meta('_kunaki_submitted') && ''===(string)$a->get_meta('_kunaki_fulfillment_error') && !as_next_scheduled_action('kunaki_wc_submit_order',['order_id'=>$c['a']],'kunaki-woocommerce'), $a->get_meta_data());
 break;
case 'catchup2':
 $c=get_option('stage_catchup'); $f=new Kunaki_Order_Fulfillment();
 $m=function($id,$k){ return (string)wc_get_order($id)->get_meta($k); };
 $kmeta=function($id){ return array_values(array_filter(array_map(fn($d)=>$d->key,wc_get_order($id)->get_meta_data()),fn($k)=>0===strpos($k,'_kunaki'))); };
 $pending=function(){ return as_get_scheduled_actions(['hook'=>'kunaki_wc_send_alert','status'=>'pending','per_page'=>-1],'ids'); };
 $log=function(){ return get_option('mock_kunaki_log',[]); };
 $listed=function(){ return array_map(fn($o)=>$o->get_id(),(new Kunaki_Retry_Queue())->get_failed_orders()); };
 // The alerts case leaves alerts off. Turn email alerts on so the alert for a held order is exercised.
 update_option('kunaki_wc_alert_email_address','ops@example.com'); update_option('kunaki_wc_alert_email_enabled','yes'); update_option('kunaki_wc_alerts_enabled','yes');
 // Three orders the scan must leave alone, for reasons unit tests can only fake: a submission queued in
 // Action Scheduler, a held submission lock, and a durable "submitted" marker.
 global $wpdb; $row=function($name,$value) use($wpdb){ $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",$name,$value)); };
 as_enqueue_async_action('kunaki_wc_submit_order',['order_id'=>$c['h']],'kunaki-woocommerce'); $row('_kunaki_lock_'.$c['i'],(string)(time()+300)); $row('_kunaki_submitted_'.$c['j'],'KUNHAND');
 $untouched=function() use($c,$kmeta){ return !$kmeta($c['h']) && !$kmeta($c['i']) && !$kmeta($c['j']); };
 do_action('kunaki_wc_retry_failed_orders');
 t('catch-up: recent order with Kunaki shipping is sent automatically', 'yes'===$m($c['a'],'_kunaki_submitted') && log_has('<Order>'), $kmeta($c['a']));
 $an=implode("\n",array_map(fn($n)=>$n->content,wc_get_order_notes(['order_id'=>$c['a']])));
 t('catch-up: that order went through the scan, then the retry cron', false!==strpos($an,'It will be sent to Kunaki automatically') && false!==strpos($an,'Order submitted to Kunaki successfully'), $an);
 t('catch-up: orders with a queued submission, a lock or a durable marker are left alone', $untouched(), [$kmeta($c['h']),$kmeta($c['i']),$kmeta($c['j'])]);
 t('catch-up: order paid 3 days ago is held, not sent', ''===$m($c['b'],'_kunaki_submitted') && 'yes'===$m($c['b'],'_kunaki_fulfillment_exhausted') && false!==strpos($m($c['b'],'_kunaki_fulfillment_last_error'),'more than a day'), $kmeta($c['b']));
 foreach(['e','f'] as $k) t("catch-up: recent order ($k) with no Kunaki shipping is held, not sent", ''===$m($c[$k],'_kunaki_submitted') && 'yes'===$m($c[$k],'_kunaki_fulfillment_exhausted') && 'yes'===$m($c[$k],'_kunaki_needs_shipping') && false!==strpos($m($c[$k],'_kunaki_fulfillment_last_error'),'cheapest'), $kmeta($c[$k]));
 t('catch-up: only the one order was sent, and Kunaki was never asked for rates', 1===count($log()) && !log_has('<ShippingOptions'), $log());
 t('catch-up: order paid 40 days ago is outside the window', !$kmeta($c['c']), $kmeta($c['c']));
 t('catch-up: order with no discs is ignored', !$kmeta($c['d']), $kmeta($c['d']));
 t('catch-up: order that already had a record is left alone', 'No matching Kunaki shipping method found on this order.'===$m($c['g'],'_kunaki_fulfillment_last_error') && ''===$m($c['g'],'_kunaki_needs_shipping'), $kmeta($c['g']));
 t('catch-up: held orders are on the Failures page', !array_diff([$c['b'],$c['e'],$c['f']],$listed()), $listed());
 t('catch-up: the notice counts the 3 held orders', 3===(int)get_option('kunaki_wc_catch_up_notice'), get_option('kunaki_wc_catch_up_notice'));
 t('catch-up: the scan is finished', false===get_option('kunaki_wc_catch_up'), get_option('kunaki_wc_catch_up'));
 // One alert for the three held orders, not one each. No mail server here, so the mail is captured, and so is a Slack post if the alerts case left Slack on.
 $GLOBALS['mails']=[]; add_filter('pre_wp_mail',function($r,$a){ if('ops@example.com'===($a['to']??'')) $GLOBALS['mails'][]=$a; return true; },10,2);
 add_filter('pre_http_request',function($r,$args,$url){ return false===strpos($url,'slack') ? $r : ['headers'=>[],'body'=>'ok','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]]; },5,3);
 $q=$pending(); $qa=$q ? ActionScheduler::store()->fetch_action(reset($q))->get_args() : [];
 t('catch-up: the held orders raise one alert between them, not one each', 1===count($q) && 'catch_up_orders_held'===($qa[0]??''), [count($q),$qa]);
 foreach($q as $x) ActionScheduler::runner()->process_action($x,'stage');
 $mail=$GLOBALS['mails'][0]??['subject'=>'','message'=>''];
 t('catch-up: that alert says how many orders are waiting and links to the Failures page', 1===count($GLOBALS['mails']) && false!==strpos($mail['subject'],'3 orders are waiting to be sent to Kunaki') && false!==strpos($mail['message'],'3 disc orders were paid') && false!==strpos($mail['message'],'page=kunaki-failed-orders') && !$pending(), $GLOBALS['mails']);
 $snap=function() use($c,$m){ $s=[]; foreach(['b','e','f'] as $k) foreach(['_kunaki_submitted','_kunaki_fulfillment_exhausted','_kunaki_fulfillment_last_error','_kunaki_fulfillment_failed_at','_kunaki_fulfillment_error','_kunaki_chosen_shipping'] as $key) $s[]=$m($c[$k],$key); return $s; };
 // Replay the whole window, as a scan that was interrupted and started again would.
 $s1=$snap(); $n1=count($log()); update_option('kunaki_wc_catch_up',['from'=>time()-60*DAY_IN_SECONDS,'to'=>time(),'cursor'=>0,'sent'=>0,'held'=>0],false); do_action('kunaki_wc_retry_failed_orders');
 t('catch-up: scanning the same window again changes nothing', $s1===$snap() && $n1===count($log()) && 3===(int)get_option('kunaki_wc_catch_up_notice') && false===get_option('kunaki_wc_catch_up') && $untouched() && !$kmeta($c['c']) && !$kmeta($c['d']), [$n1,count($log()),get_option('kunaki_wc_catch_up_notice'),get_option('kunaki_wc_catch_up')]);
 // Alerts are still on here, so this would catch a second alert for orders that were already reported.
 t('catch-up: the second scan raises no alert', !$pending() && 1===count($GLOBALS['mails']), [count($pending()),count($GLOBALS['mails'])]);
 update_option('kunaki_wc_alerts_enabled','no');
 // Done with the three orders that were only there to be skipped.
 foreach(as_get_scheduled_actions(['hook'=>'kunaki_wc_submit_order','status'=>'pending','per_page'=>-1],'ids') as $q) ActionScheduler::store()->cancel_action($q);
 $wpdb->delete($wpdb->options,['option_name'=>'_kunaki_lock_'.$c['i']]); $wpdb->delete($wpdb->options,['option_name'=>'_kunaki_submitted_'.$c['j']]);
 foreach(['h','i','j'] as $k) wc_get_order($c[$k])->update_status('cancelled');
 // Retry on the held order that has shipping from checkout.
 delete_option('mock_kunaki_log'); $r=$f->prepare_for_retry($c['b']);
 t('catch-up: Retry sends the order held for its age', true===$r && 'yes'===$m($c['b'],'_kunaki_submitted'), $r);
 t('catch-up: Retry on an order with Kunaki shipping makes no rate lookup', 1===count($log()) && log_has('<Order>') && !log_has('<ShippingOptions'), $log());
 // Retry while Kunaki can't be reached.
 update_option('mock_kunaki_mode','down'); $was=[$m($c['f'],'_kunaki_fulfillment_exhausted'),$m($c['f'],'_kunaki_fulfillment_last_error'),$m($c['f'],'_kunaki_fulfillment_failed_at')]; $r=$f->prepare_for_retry($c['f']);
 t('catch-up: Retry with Kunaki unreachable sends nothing and says why', is_wp_error($r) && ''!==$r->get_error_message() && ''===$m($c['f'],'_kunaki_submitted'), $r);
 t('catch-up: that order keeps its record and stays on the Failures page', 'yes'===$was[0] && $was===[$m($c['f'],'_kunaki_fulfillment_exhausted'),$m($c['f'],'_kunaki_fulfillment_last_error'),$m($c['f'],'_kunaki_fulfillment_failed_at')] && ''===$m($c['f'],'_kunaki_chosen_shipping') && in_array($c['f'],$listed(),true), [$kmeta($c['f']),$listed()]);
 update_option('mock_kunaki_mode','ok'); delete_option('kunaki_wc_circuit_breaker');
 // Retry on an order with no Kunaki shipping: the dearer rate is listed first.
 update_option('mock_kunaki_two_rates',1); delete_option('mock_kunaki_log'); $r=$f->prepare_for_retry($c['e']); $l=$log();
 t('catch-up: Retry sends the order that had no Kunaki shipping', true===$r && 'yes'===$m($c['e'],'_kunaki_submitted'), $r);
 t('catch-up: it asks Kunaki for rates, then orders', 2===count($l) && 0===strpos($l[0],'<ShippingOptions') && 0===strpos($l[1],'<Order>'), $l);
 t('catch-up: the cheaper rate is used although it is listed second', isset($l[1]) && false!==strpos($l[1],'<ShippingDescription>USPS First Class Mail</ShippingDescription>') && false===strpos($l[1],'UPS Ground'), $l);
 t('catch-up: the choice is stored on the order', 'USPS First Class Mail'===$m($c['e'],'_kunaki_chosen_shipping'), $kmeta($c['e']));
 $notes=implode("\n",array_map(fn($n)=>$n->content,wc_get_order_notes(['order_id'=>$c['e']])));
 t('catch-up: the order note names the option, its delivery time and its price', false!==strpos($notes,'Sending it with the cheapest Kunaki option for its address: USPS First Class Mail (2-5 business days), Kunaki price 6.29.'), $notes);
 // Retry on the failure from before this version: its message never said Retry would choose shipping.
 delete_option('mock_kunaki_log'); $r=$f->prepare_for_retry($c['g']);
 t('catch-up: first Retry on an older failure sends nothing and explains', is_wp_error($r) && false!==strpos($r->get_error_message(),'cheapest') && !$log() && ''===$m($c['g'],'_kunaki_submitted') && 'yes'===$m($c['g'],'_kunaki_needs_shipping'), [$r,$log()]);
 $r=$f->prepare_for_retry($c['g']); t('catch-up: second Retry sends it', true===$r && 'yes'===$m($c['g'],'_kunaki_submitted'), $r);
 $r=$f->prepare_for_retry($c['f']); t('catch-up: the order whose lookup failed is sent by a later Retry', true===$r && 'yes'===$m($c['f'],'_kunaki_submitted'), $r);
 delete_option('mock_kunaki_two_rates');
 t('catch-up: no alert jobs are left queued', !$pending(), count($pending()));
 break;
case 'alerts2':
 t('deactivation cancels queued alerts', !as_get_scheduled_actions(['hook'=>'kunaki_wc_send_alert','status'=>'pending','per_page'=>-1],'ids'));
 // Left flagged, the first outage after reactivation would send no alert.
 global $wpdb; $left=$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='kunaki_wc_alert_outage_open'");
 t('deactivation forgets the flagged outage', null===$left, $left);
 break;
}
