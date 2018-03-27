<?php

function AgileWC_script()
{
    global $AGILEWC_DOMAIN, $AGILEWC_KEY, $current_user, $AGILEWC_SYNC_OPTIONS;
    //get_currentuserinfo();
    wp_get_current_user();
    $email = $current_user->user_email;

    $script = "";

    if ((isset($AGILEWC_DOMAIN) && $AGILEWC_DOMAIN) && (isset($AGILEWC_KEY) && $AGILEWC_KEY)) {
       
        $script .= '<script src="https://' . $AGILEWC_DOMAIN . '.agilecrm.com/stats/min/agile-min.js"></script>';
       
        $script .= '<script>';
        $script .= '_agile.set_account("' . $AGILEWC_KEY . '","' . $AGILEWC_DOMAIN . '");';

        if (isset($AGILEWC_SYNC_OPTIONS['track_visitors'])) {
            $script .= '_agile.track_page_view();';
        }

        if (isset($AGILEWC_SYNC_OPTIONS['web_rules'])) {
            $script .= '_agile_execute_web_rules();';
        }

        if (isset($email) && $email != NULL && $email != '') {
            $script .= '_agile.set_email("' . $email . '");';
        } elseif (isset($_SESSION['agileWCTrackEmail'])) {
            $script .= '_agile.set_email("' . $_SESSION['agileWCTrackEmail'] . '");';
        }

        $script .= '</script>';
    }

    echo $script;
}

function AgileWC_created_customer()
{
    $cusIdArr = func_get_args();
    $userId = $cusIdArr[0];
    $cusId = get_post_meta($userId, '_customer_user', true);

    $cusEmail = get_post_meta($userId, '_billing_email', true);

    $customer = new AgileCRM_Customer();
    $customer->first_name = get_post_meta($userId, '_billing_first_name', true);
    $customer->last_name = get_post_meta($userId, '_billing_last_name', true);
    $customer->company = get_post_meta($userId, '_billing_company', true);
    $customer->email = $cusEmail;
    $customer->phone = get_post_meta($userId, '_billing_phone', true);
    $customer->address = AgileWC_getUserAddress($userId);

    $agilecrm = new AgileCRM();
    $agilecrm->customerEmail = $cusEmail;
    $agilecrm->hook = AgileCRM::$hooks['customer.created'];
    $agilecrm->payLoad = $customer->getAgileFormat();

    $res = $agilecrm->post();

    if ($res && $cusId == 0) {
        $_SESSION['agileWCTrackEmail'] = $cusEmail;
    }
}

function AgileWC_new_order()
{
    $_SESSION['agileWCOrderHook'] = AgileCRM::$hooks['order.created'];
}

function AgileWC_order_status_changed()
{
    global $AGILEWC_SYNC_OPTIONS;
    $ordersArr = func_get_args();
    $wcorder = new WC_Order($ordersArr[0]);
    $order = AgileWC_getOrder($wcorder);

    $orderHook = AgileCRM::$hooks['order.updated'];
    if (isset($_SESSION['agileWCOrderHook'])) {
        $orderHook = $_SESSION['agileWCOrderHook'];
        unset($_SESSION['agileWCOrderHook']);
    }

    $agilecrm = new AgileCRM();
    $agilecrm->customerEmail = version_compare( WC_VERSION, '3.0.0', '<' ) ? $wcorder->billing_email : $wcorder->get_billing_email();
    $agilecrm->hook = $orderHook;
    $agilecrm->payLoad = array("order" => $order);
    $agilecrm->syncAsTags = "";
    
    if (isset($AGILEWC_SYNC_OPTIONS['sync_product_tags'])) {
        $agilecrm->syncAsTags .= "_products";
    }
    if (isset($AGILEWC_SYNC_OPTIONS['sync_category_tags'])) {
        $agilecrm->syncAsTags .= "_categories";
    }
    
    $agilecrm->post();
}

function AgileWC_new_customer_note()
{   
    $parmArr = func_get_args();
    $wcorder = new WC_Order($parmArr[0]['order_id']);

    $agileData = array(
        "subject" => "Customer note on order #" . $parmArr[0]['order_id'],
        "description" => $parmArr[0]['customer_note']
    );

    $agilecrm = new AgileCRM();
    $agilecrm->customerEmail = version_compare( WC_VERSION, '3.0.0', '<' ) ? $wcorder->billing_email : $wcorder->get_billing_email();
    $agilecrm->hook = AgileCRM::$hooks['note.created'];
    $agilecrm->payLoad = array("order" => AgileWC_getOrder($wcorder), "note" => $agileData);
    $agilecrm->post();
}

function AgileWC_getOrder($wcorder)
{

    $order = new AgileCRM_Order();
    $order->id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $wcorder->id : $wcorder->get_id();
    $order->status = version_compare( WC_VERSION, '3.0.0', '<' ) ? AgileWC_getOrderStatusName($wcorder->post_status) : AgileWC_getOrderStatusName($wcorder->get_status());
    $order->billingAddress = str_replace('<br/>', ", ", $wcorder->get_formatted_billing_address());
    $order->shippingAddress = str_replace('<br/>', ", ", $wcorder->get_formatted_shipping_address());
    $order->grandTotal = number_format($wcorder->get_total(), 2, '.', '');
    $order->note = version_compare( WC_VERSION, '3.0.0', '<' ) ? $wcorder->customer_note : $wcorder->get_customer_note();
    
    $items = $wcorder->get_items();
    foreach ($items as $item) {
        $product = new AgileCRM_Product();
        $product->id = $item['product_id'];
        $product->name = $item['name'];
        $product->quantity = $item['qty'];

        $terms = wp_get_post_terms($item['product_id'], 'product_cat', array('fields' => 'names'));
        if ($terms && !is_wp_error($terms)) {
            $product->categories = $terms;
        }
        
        $order->products[] = $product;
    }
    return $order;
}

function AgileWC_getUserAddress($user_id)
{
    global $states;
    $countryCode = get_post_meta($user_id, '_billing_country', true);
    $stateCode = get_post_meta($user_id, '_billing_state', true);
    $agileAddress = new AgileCRM_Address();
    $agileAddress->address = get_post_meta($user_id, '_billing_address_1', true) . ' ' . get_post_meta($user_id, '_billing_address_2', true);
    $agileAddress->city = get_post_meta($user_id, '_billing_city', true);
    $agileAddress->state = isset($states[$countryCode][$stateCode]) ? $states[$countryCode][$stateCode] : "";
    $agileAddress->zip = get_post_meta($user_id, '_billing_postcode', true);
    $agileAddress->country = WC()->countries->countries[$countryCode];
    return $agileAddress;
}

function AgileWC_getOrderStatusName($statusCode)
{
    $orderStatuses = array(
        'wc-pending' => 'Pending Payment',
        'wc-processing' => 'Processing',
        'wc-on-hold' => 'On Hold',
        'wc-completed' => 'Completed',
        'wc-cancelled' => 'Cancelled',
        'wc-refunded' => 'Refunded',
        'wc-failed' => 'Failed',
    );

    return isset($orderStatuses[$statusCode]) ? $orderStatuses[$statusCode] : $statusCode;
}

function get_woo_customers_agile(){
    $query = new WC_Order_Query();
    //$query->set( 'customer', 'woocommerce@woocommerce.com' );

    $args = array('post_type' => 'shop_order', 'post_status' => array('wc-cancelled'), 'posts_per_page' => -1); 
    $orders = get_posts( $args );
    $order_ids = wp_list_pluck($orders, 'ID');
    $exist = 0;
    foreach($order_ids as $order_id){
        $wcorder = new WC_Order($order_id);
        $all_emails[] = $wcorder->billing_email;
    } 

    $all_unique_emails = array_unique($all_emails);
    foreach($all_unique_emails as $email){
        $result = curl_wrap("contacts/search/email/".$email, null, "GET", "application/json");
        $result = json_decode($result, false, 512, JSON_BIGINT_AS_STRING);
        
        if(count($result)>0){
            
        }
    }
}

function curl_wrap($entity, $data, $method, $content_type) {
    if ($content_type == NULL) {
        $content_type = "application/json";
    }

    $AGILEWC_DOMAIN = get_option('agile-domain-setting');
    $AGILEWC_KEY = get_option('agile-key-setting');
    $AGILEWC_EMAIL = 'agile@suko.es';
    $agile_url = "https://" . $AGILEWC_DOMAIN . ".agilecrm.com/dev/api/" . $entity;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, true);
    switch ($method) {
        case "POST":
            $url = $agile_url;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            break;
        case "GET":
            $url = $agile_url;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            break;
        case "PUT":
            $url = $agile_url;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            break;
        case "DELETE":
            $url = $agile_url;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        default:
            break;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-type : $content_type;", 'Accept : application/json'
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $AGILEWC_EMAIL . ':' . $AGILEWC_KEY);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}