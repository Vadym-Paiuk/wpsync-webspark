<?php
    /*
    Plugin Name: WPsync Webspark
    Plugin URI: https://github.com/Vadym-Paiuk/wpsync-webspark/
    Description: Webspark test job
    Author: Vadym Paiuk
    Version: 1.0
    */
    
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    register_activation_hook( __FILE__, 'my_plugin_activation_function' );

    function my_plugin_activation_function() {
        wp_clear_scheduled_hook( 'my_hourly_event' );
        wp_schedule_event( time(), 'hourly', 'my_hourly_event');
    }
    
    add_action( 'my_hourly_event', 'do_this_hourly' );
    
    function do_this_hourly() {
        $response = wp_remote_get( 'https://wp.webspark.dev/wp-api/products', [ 'timeout' => 20 ] );
        
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $data_array = json_decode( $body, true );
            
            if( !$data_array['error'] && !empty( $data_array['data'] ) ){
                wpsync_webspark( $data_array['data'] );
            }else{
                do_this_hourly();
            }
        } else {
            $error_message = is_wp_error( $response ) ? $response->get_error_message() : 'Request failed.';
            do_this_hourly();
        }
    }
    
    function wpsync_webspark( $data ) {
        foreach ( $data as $key => $item ) {
            $data[$item['sku']] = $item;
            unset( $data[$key] );
        }
        
        $args = array(
            'limit' => -1
        );
        
        $products = wc_get_products( $args );
        
        foreach ( $products as $key => $item ) {
            $products[$item->get_sku()] = $item;
            unset( $products[$key] );
        }
        
        $ar_products_add = array_diff_key( $data, $products );
        $ar_products_edit = array_intersect_key( $products, $data );
        $ar_products_delete = array_diff_key( $products, $data );
        
        
        if( !empty( $ar_products_add ) ) {
            foreach ( $ar_products_add as $item ) {
                $product = new WC_Product();
                
                $price_string = $item['price'];
                $price_string = preg_replace('/[^0-9.]/', '', $price_string);
                $price = floatval($price_string);
                
                $product->set_name( $item['name'] );
                $product->set_description( $item['description'] );
                $product->set_regular_price( $price );
                $product->set_sku( $item['sku'] );
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $item['in_stock'] );
                $product->set_status( 'publish' );
                
                $product_id = $product->save();
                
                $url = get_redirected_url( $item['picture'] );
                $attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
                if ( !is_wp_error( $attachment_id ) ) {
                    set_post_thumbnail( $product_id, $attachment_id );
                }
            }
        }
        
        if( !empty( $ar_products_edit ) ) {
            foreach ( $ar_products_edit as $key => $product ) {
                if( $product->get_name() !== $data[$key]['name'] ){
                    $product->set_name( $data[$key]['name'] );
                }
                
                if( $product->get_description() !== $data[$key]['description'] ){
                    $product->set_description( $data[$key]['description'] );
                }
                
                if( $product->get_stock_quantity() !== $data[$key]['in_stock'] ){
                    $product->set_stock_quantity( $data[$key]['in_stock'] );
                }
                
                $price_string = $data[$key]['price'];
                $price_string = preg_replace('/[^0-9.]/', '', $price_string);
                $price = floatval( $price_string );
                
                if( $product->get_regular_price() !== $price ){
                    $product->set_regular_price( $price );
                }
                
                $url = get_redirected_url( $data[$key]['picture'] );
                $attachment_id = media_sideload_image( $url, $product->get_id(), null, 'id' );
                if( !is_wp_error( $attachment_id ) ){
                    $product->set_image_id( $attachment_id );
                }
                
                $product->save();
            }
        }
        
        if( !empty( $ar_products_delete ) ) {
            foreach ( $ar_products_delete as $product ) {
                $product->delete( true );
            }
        }
    }
    
    function get_redirected_url( $url ) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        $redirected_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $redirected_url;
    }
    
    register_deactivation_hook( __FILE__, 'my_plugin_deactivation_function' );

    function my_plugin_deactivation_function() {
        wp_clear_scheduled_hook( 'my_hourly_event' );
    }