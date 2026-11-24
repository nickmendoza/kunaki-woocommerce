import json,re,subprocess,sys,urllib.parse,urllib.request,http.cookiejar,time,concurrent.futures as cf
B='http://127.0.0.1:18088'; R=[]
def wp(*a,env=None):
    cmd=['docker','exec','-e','WORDPRESS_DEBUG=1']+sum([['-e',f'{k}={v}'] for k,v in (env or {}).items()],[])+['-i','kunakistaging-cli-1','wp']+list(a)
    p=subprocess.run(cmd,capture_output=True,text=True); open('err-full.txt','a').write(p.stderr); return p.stdout.strip()
def scen(n):
    o=wp('eval-file','/tmp/s.php',env={'SCEN':n}); print(o); 
def t(n,ok,x=''):
    print(('PASS' if ok else 'FAIL')+' '+n+('' if ok else f'  -- {str(x)[:400]}'))
def clear():
    wp('eval','global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%kunaki_ship_%\' OR option_name LIKE \'%kunaki_wc_rates_unavailable%\'"); wp_cache_flush(); delete_option("kunaki_wc_circuit_breaker");')
class Store:
    def __init__(s): s.tok=None
    def req(s,m,p,d=None):
        h={'Content-Type':'application/json'}
        if s.tok: h['Cart-Token']=s.tok
        r=urllib.request.Request(B+'/wp-json/wc/store/v1'+p,method=m,headers=h,data=json.dumps(d).encode() if d else None)
        try: resp=urllib.request.urlopen(r,timeout=60)
        except urllib.error.HTTPError as e: return json.loads(e.read())
        s.tok=resp.headers.get('Cart-Token') or s.tok; return json.loads(resp.read())
def rates(c): return [x for pk in c.get('shipping_rates',[]) for x in pk.get('shipping_rates',[])]
ids=json.loads(wp('option','get','stage_ids','--format=json'))
ADDR={'shipping_address':{'first_name':'A','last_name':'B','address_1':'1 St','city':'NYC','state':'NY','postcode':'10001','country':'US'},'billing_address':{'first_name':'A','last_name':'B','address_1':'1 St','city':'NYC','state':'NY','postcode':'10001','country':'US','email':'c@example.test'}}
def browser():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def checkout_flow():
    print('--- outage and recovery, Store API + block pages')
    wp('option','update','mock_kunaki_mode','down'); clear()
    s=Store(); s.req('GET','/cart'); s.req('POST','/cart/add-item',{'id':ids['disc'],'quantity':1}); c=s.req('POST','/cart/update-customer',ADDR)
    t('during outage the disc package has no rates',rates(c)==[],rates(c))
    t('outage flag set',wp('transient','get','kunaki_wc_rates_unavailable')!='')
    b=browser(); b.open(B+f'/?add-to-cart={ids["disc"]}',timeout=60).read()
    html=b.open(B+'/checkout/',timeout=60).read().decode(); t('block checkout page shows outage notice','temporarily unavailable' in html,len(html))
    html=b.open(B+'/cart/',timeout=60).read().decode(); t('block cart page shows outage notice','temporarily unavailable' in html)
    n=len(json.loads(wp('option','get','mock_kunaki_log','--format=json') or '[]'))
    s.req('GET','/cart'); n2=len(json.loads(wp('option','get','mock_kunaki_log','--format=json') or '[]')); t('refreshing cart during outage does not call Kunaki again (failure cached)',n==n2,(n,n2))
    wp('option','update','mock_kunaki_mode','ok'); clear()
    c=s.req('GET','/cart'); t('rates return after recovery with NO cart or address change',len(rates(c))==1,c.get('shipping_rates'))
    html=b.open(B+'/checkout/',timeout=60).read().decode(); t('notice gone after recovery','temporarily unavailable' not in html)
    print('--- rates cached before an outage')
    wp('option','update','mock_kunaki_mode','down'); wp('eval','set_transient("kunaki_wc_rates_unavailable",time(),120);')
    html=b.open(B+'/checkout/',timeout=60).read().decode(); t('customer with working cached rates sees no false notice','temporarily unavailable' not in html)
    wp('option','update','mock_kunaki_mode','ok'); clear()
    print('--- full block-checkout order')
    wp('option','delete','mock_kunaki_log')
    c=s.req('POST','/cart/update-customer',ADDR); pk=c['shipping_rates'][0]; rid=pk['shipping_rates'][0]['rate_id']; s.req('POST','/cart/select-shipping-rate',{'package_id':pk['package_id'],'rate_id':rid})
    r=s.req('POST','/checkout',{**ADDR,'payment_method':'cod'}); oid=r.get('order_id'); t('Store API checkout creates order',bool(oid) and r.get('status') in('processing','on-hold','pending'),r)
    return oid
def variations_cart():
    print('--- mixed variation cart through real shipping packages')
    clear(); s=Store(); s.req('GET','/cart'); s.req('POST','/cart/add-item',{'id':ids['var']['DVD'],'quantity':1}); s.req('POST','/cart/add-item',{'id':ids['var']['CD'],'quantity':2}); wp('option','delete','mock_kunaki_log'); c=s.req('POST','/cart/update-customer',ADDR)
    log=' '.join(json.loads(wp('option','get','mock_kunaki_log','--format=json') or '[]'))
    t('rate request uses each variation ID','PXVARDVD01' in log and 'PXVARCD001' in log,log); t('disc rates offered',len(rates(c))==1,c.get('shipping_rates'))
    s2=Store(); s2.req('GET','/cart'); s2.req('POST','/cart/add-item',{'id':ids['var']['DVD'],'quantity':1}); s2.req('POST','/cart/add-item',{'id':ids['var']['Sticker'],'quantity':1}); c=s2.req('POST','/cart/update-customer',ADDR)
    t('disc variation and non-disc variation split into 2 packages',len(c['shipping_rates'])==2,c['shipping_rates'])
def country_flow():
    print('--- country Kunaki does not ship to, Store API')
    clear(); wp('option','update','mock_kunaki_mode','ok')
    BR={k:{**v,'country':'BR','state':'SP','postcode':'01000-000','city':'Sao Paulo'} for k,v in ADDR.items()}
    codes=lambda c:[e.get('code') for e in c.get('errors',[])]
    s=Store(); s.req('GET','/cart'); s.req('POST','/cart/add-item',{'id':ids['disc'],'quantity':1}); wp('option','delete','mock_kunaki_log')
    c=s.req('POST','/cart/update-customer',BR)
    t('disc cart to Brazil reports a cart error',('kunaki_country_not_supported' in codes(c)),c.get('errors'))
    msg=' '.join(e.get('message','') for e in c.get('errors',[])); t('cart error names the country','be shipped to Brazil' in msg,msg)
    t('no Kunaki call for an unsupported country',json.loads(wp('option','get','mock_kunaki_log','--format=json') or '[]')==[])
    t('no disc rates for Brazil',rates(c)==[],rates(c))
    r=s.req('POST','/checkout',{**BR,'payment_method':'cod'}); t('checkout to Brazil is refused',not r.get('order_id'),r)
    c=s.req('POST','/cart/update-customer',ADDR)
    t('cart error clears when the address changes to a supported country','kunaki_country_not_supported' not in codes(c),c.get('errors')); t('rates return for US',len(rates(c))==1,c.get('shipping_rates'))
    s2=Store(); s2.req('GET','/cart'); s2.req('POST','/cart/add-item',{'id':ids['var']['Sticker'],'quantity':1}); c=s2.req('POST','/cart/update-customer',BR)
    t('non-disc cart to Brazil has no Kunaki error','kunaki_country_not_supported' not in codes(c),c.get('errors'))
    wp('option','update','mock_kunaki_mode','down'); clear(); wp('eval','set_transient("kunaki_wc_rates_unavailable",time(),120);')
    s3=Store(); s3.req('GET','/cart'); s3.req('POST','/cart/add-item',{'id':ids['disc'],'quantity':1}); c=s3.req('POST','/cart/update-customer',BR)
    msg=' '.join(e.get('message','') for e in c.get('errors',[])); t('during an outage, Brazil still gets the country message','Brazil' in msg and 'temporarily' not in msg,msg)
    wp('option','update','mock_kunaki_mode','ok'); clear()
def admin():
    print('--- admin: password field and variation field')
    b=browser(); d=urllib.parse.urlencode({'log':'admin','pwd':'stagepass','wp-submit':'Log In','testcookie':'1'}).encode()
    urllib.request.install_opener(b); b.open(B+'/wp-login.php').read(); b.open(urllib.request.Request(B+'/wp-login.php',data=d),timeout=60).read()
    U=B+'/wp-admin/admin.php?page=wc-settings&tab=kunaki'; h=b.open(U,timeout=60).read().decode()
    t('settings page loaded','kunaki_wc_password' in h,h[:200]); t('saved password absent from page HTML','S3cret-Stage-Pw!' not in h)
    m=re.search(r'<input[^>]*name="kunaki_wc_password"[^>]*>',h,re.S); t('password input value empty',m and 'value=""' in m.group(0),m and m.group(0))
    t('autocomplete disabled',m and 'autocomplete="new-password"' in m.group(0)); t('remove-data checkbox present','kunaki_wc_delete_data_on_uninstall' in h)
    non=re.search(r'name="_wpnonce" value="([^"]+)"',h).group(1)
    F={'_wpnonce':non,'save':'Save changes','kunaki_wc_user_id':'pub@example.test','kunaki_wc_mode':'TEST','kunaki_wc_sync_interval':'15','kunaki_wc_max_retries':'3','kunaki_wc_circuit_threshold':'5','kunaki_wc_circuit_cooldown':'5','_wp_http_referer':'/wp-admin/admin.php?page=wc-settings&tab=kunaki'}
    b.open(urllib.request.Request(U,data=urllib.parse.urlencode({**F,'kunaki_wc_password':''}).encode()),timeout=60).read()
    t('saving blank keeps password',wp('eval','echo get_option("kunaki_wc_password");')=='S3cret-Stage-Pw!',wp('eval','echo get_option("kunaki_wc_password");'))
    h=b.open(U,timeout=60).read().decode(); non=re.search(r'name="_wpnonce" value="([^"]+)"',h).group(1); F['_wpnonce']=non
    b.open(urllib.request.Request(U,data=urllib.parse.urlencode({**F,'kunaki_wc_password':'New<Pw>%41 x'}).encode()),timeout=60).read()
    t('new password saved intact (special chars)',wp('eval','echo get_option("kunaki_wc_password");')=='New<Pw>%41 x',wp('eval','echo get_option("kunaki_wc_password");'))
    wp('option','update','kunaki_wc_password','S3cret-Stage-Pw!')
    E=B+f'/wp-admin/post.php?post={ids["album"]}&action=edit'; h=b.open(E,timeout=60).read().decode()
    ln=re.search(r'load_variations_nonce["\']?:\s*["\']([^"\']+)',h); sn=re.search(r'save_variations_nonce["\']?:\s*["\']([^"\']+)',h)
    t('product edit page has variation nonces',bool(ln and sn))
    aj=B+'/wp-admin/admin-ajax.php'; x=b.open(urllib.request.Request(aj,data=urllib.parse.urlencode({'action':'woocommerce_load_variations','security':ln.group(1),'product_id':ids['album'],'attributes':'','page':1,'per_page':10}).encode()),timeout=60).read().decode()
    t('variation editor renders Kunaki field','kunaki_variation_product_id[0]' in x and 'PXVARCD001' in x,x[:300])
    vid=ids['var']['Sticker']; d={'action':'woocommerce_save_variations','security':sn.group(1),'product_id':ids['album'],'product-type':'variable','variable_post_id[0]':vid,'variation_menu_order[0]':'0','attribute_format[0]':'Sticker','variable_regular_price[0]':'12','variable_stock_status[0]':'instock','kunaki_variation_product_id[0]':'PXSTICKER1'}
    b.open(urllib.request.Request(aj,data=urllib.parse.urlencode(d).encode()),timeout=60).read()
    t('saving variation form stores its Kunaki ID',wp('post','meta','get',str(vid),'_kunaki_product_id')=='PXSTICKER1',wp('post','meta','get',str(vid),'_kunaki_product_id'))
    d['kunaki_variation_product_id[0]']='bad'; b.open(urllib.request.Request(aj,data=urllib.parse.urlencode(d).encode()),timeout=60).read()
    t('invalid ID rejected, previous kept',wp('post','meta','get',str(vid),'_kunaki_product_id')=='PXSTICKER1')
    d['kunaki_variation_product_id[0]']=''; b.open(urllib.request.Request(aj,data=urllib.parse.urlencode(d).encode()),timeout=60).read()
    t('blank removes ID',wp('post','meta','get',str(vid),'_kunaki_product_id')=='')
def stampede():
    print('--- concurrent breaker: 8 real processes after cooldown')
    wp('option','update','mock_kunaki_mode','ok'); wp('eval','update_option("kunaki_wc_circuit_breaker",wp_json_encode(["state"=>"open","failure_count"=>5,"last_failure_at"=>time()-999,"opened_at"=>time()-999]),false);')
    T=time.time()+8
    code=f'time_sleep_until({T}); $r=(new Kunaki_Circuit_Breaker())->pre_request(); echo $r===true?"PROBE":"BLOCKED";'
    with cf.ThreadPoolExecutor(8) as e: out=list(e.map(lambda _:wp('eval',code),range(8)))
    t('exactly one of 8 got the probe',out.count('PROBE')==1,out); print('   ',out); wp('option','delete','kunaki_wc_circuit_breaker')
def uninstall():
    print('--- uninstall on real WP')
    oid=wp('eval','$o=wc_create_order(); $o->update_meta_data("_kunaki_order_id","KEEPME"); $o->update_meta_data("_kunaki_tracking_id","T1"); $o->save(); echo $o->get_id();')
    pid=str(ids['disc'])
    wp('plugin','deactivate','kunaki-woocommerce'); wp('plugin','uninstall','kunaki-woocommerce')
    t('default uninstall keeps order history',wp('eval',f'echo wc_get_order({oid})->get_meta("_kunaki_order_id");')=='KEEPME'); t('default uninstall keeps product link',wp('post','meta','get',pid,'_kunaki_product_id')=='PXSIMPLE01')
    t('default uninstall removes password',wp('option','get','kunaki_wc_password')=='')
    cp="mkdir -p wp-content/plugins/kunaki-woocommerce; cd /src && tar cf - --exclude=vendor --exclude=tests --exclude=.git --exclude=.claude --exclude=claude --exclude=claude.zip --exclude=.idea . | tar xf - -C /var/www/html/wp-content/plugins/kunaki-woocommerce"
    subprocess.run(['docker','exec','-i','kunakistaging-cli-1','sh','-c',cp]); wp('plugin','activate','kunaki-woocommerce')
    t('after reinstall the order is still linked',wp('eval',f'echo wc_get_order({oid})->get_meta("_kunaki_order_id");')=='KEEPME')
    wp('option','update','kunaki_wc_delete_data_on_uninstall','yes'); wp('plugin','deactivate','kunaki-woocommerce'); wp('plugin','uninstall','kunaki-woocommerce')
    t('opt-in uninstall removes order data',wp('eval',f'echo "[".wc_get_order({oid})->get_meta("_kunaki_order_id")."]";')=='[]',wp('eval',f'echo "[".wc_get_order({oid})->get_meta("_kunaki_order_id")."]";')); t('opt-in uninstall removes product link',wp('post','meta','get',pid,'_kunaki_product_id')=='')
    t('opt-in option itself removed',wp('option','get','kunaki_wc_delete_data_on_uninstall')=='')
for mode in ['disable','enable']:
    print(f'\n========== hpos {mode}'); print(wp('wc','hpos','sync')[:80] if mode=='enable' else '', wp('wc','hpos',mode,'--ignore-plugin-compatibility')[-90:]); wp('option','update','mock_kunaki_mode','ok'); clear()
    scen('queue'); scen('variation'); scen('sync'); scen('queries')
    oid=checkout_flow()
    if oid:
        o=wp('eval',f'$o=wc_get_order({oid}); if("processing"!==$o->get_status()){{$o->set_status("processing");$o->save();}} foreach(as_get_scheduled_actions(["hook"=>"kunaki_wc_submit_order","status"=>"pending","per_page"=>-1],"ids") as $i) ActionScheduler::runner()->process_action($i,"s"); $o=wc_get_order({oid}); echo $o->get_meta("_kunaki_submitted")."|".$o->get_meta("_kunaki_order_id");')
        t('order from block checkout is submitted to Kunaki',o.startswith('yes|KUN'),o)
    variations_cart(); country_flow()
    print('--- deactivate with queued order'); scen('park'); wp('plugin','deactivate','kunaki-woocommerce'); scen('park2'); wp('plugin','activate','kunaki-woocommerce'); scen('park3')
    scen('misc')
admin(); stampede()
print('--- WP-CLI command on real install'); print(wp('kunaki','sync','--dry-run')[:300]); print(wp('kunaki','sync')[:300])
print('--- plugin compat'); print(wp('eval','echo wp_json_encode(\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil::get_compatible_plugins_for_feature("cart_checkout_blocks"));'))
uninstall()
print('\n--- debug.log (web requests)')
urllib.request.urlopen(B+'/?kunaki_e2e_log_check=1',timeout=60).read()
L=subprocess.run(['docker','exec','kunakistaging-wp-1','cat','/var/www/html/wp-content/debug.log'],capture_output=True,text=True).stdout
t('web requests are logged (sentinel line found)','kunaki-e2e-log-check' in L,len(L))
bad=[l for l in L.splitlines() if re.search(r'PHP (Fatal|Warning|Notice|Deprecated|Parse)',l) and re.search(r'kunaki',l,re.I)]
t('no PHP errors, warnings or notices from the plugin',not bad,'\n'.join(bad[:10]))
print('fatals: '+str(sum('PHP Fatal' in l for l in L.splitlines())))
