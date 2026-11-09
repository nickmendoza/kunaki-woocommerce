set -e
cd /var/www/html
wp core install --url=http://127.0.0.1:18088 --title=Stage --admin_user=admin --admin_password=stagepass --admin_email=a@example.test --skip-email >/dev/null
wp plugin install woocommerce --version=11.2.2 --activate >/dev/null 2>&1
mkdir -p wp-content/plugins/kunaki-woocommerce wp-content/mu-plugins
cd /src && tar cf - --exclude=vendor --exclude=tests --exclude=.git --exclude=.claude --exclude=claude --exclude=claude.zip --exclude=.idea . | tar xf - -C /var/www/html/wp-content/plugins/kunaki-woocommerce
cd /var/www/html
wp plugin activate kunaki-woocommerce >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp option update woocommerce_default_country 'US:NY' >/dev/null
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null
wp option update woocommerce_currency USD >/dev/null
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"COD"}' --format=json >/dev/null
wp wc tool run install_pages --user=admin >/dev/null 2>&1 || true
wp option update kunaki_wc_user_id pub@example.test >/dev/null
wp option update kunaki_wc_password 'S3cret-Stage-Pw!' >/dev/null
wp eval '$z=WC_Shipping_Zones::get_zone(0); $z->add_shipping_method("kunaki_shipping");
 $p=new WC_Product_Simple(); $p->set_name("Disc"); $p->set_regular_price("10"); $p->set_status("publish"); $id=$p->save(); update_post_meta($id,"_kunaki_product_id","PXSIMPLE01");
 $v=new WC_Product_Variable(); $v->set_name("Album"); $v->set_status("publish"); $a=new WC_Product_Attribute(); $a->set_name("Format"); $a->set_options(["CD","DVD","Sticker"]); $a->set_variation(true); $a->set_visible(true); $v->set_attributes([$a]); $pid=$v->save();
 $ids=[]; foreach(["CD"=>"PXVARCD001","DVD"=>"PXVARDVD01","Sticker"=>""] as $o=>$k){$x=new WC_Product_Variation(); $x->set_parent_id($pid); $x->set_attributes(["format"=>$o]); $x->set_regular_price("12"); $x->set_status("publish"); $vid=$x->save(); if($k) update_post_meta($vid,"_kunaki_product_id",$k); $ids[$o]=$vid;}
 update_option("stage_ids",["disc"=>$id,"album"=>$pid,"var"=>$ids]); echo "ok\n";'
cp /tmp/kunaki-mock.php wp-content/mu-plugins/ 
