<?php


/**
 * Custom exception classes
 */
class GFJeebException extends Exception {}
class GFJeebCurlException extends Exception {}

/**
 * Class for managing the plugin
 */
class GFJeebPlugin
{
    public $urlBase;                  // string: base URL path to files in plugin
    public $options;                  // array of plugin options

    protected $txResult = null;       // Jeeb transaction results

    /**
     * Static method for getting the instance of this singleton object
     *
     * @return GFJeebPlugin
     */
    public static function getInstance()
    {
        static $instance = NULL;

        if (true === empty($instance)) {
            $instance = new self();
        }

        return $instance;
    }

    /**
     * Initialize plugin
     */
    private function __construct()
    {
        // record plugin URL base
        $this->urlBase = plugin_dir_url(__FILE__);

        add_action('init', array($this, 'init'));
    }

    /**
     * handle the plugin's init action
     */
    public function init()
    {
        // do nothing if Gravity Forms isn't enabled
        if (true === class_exists('GFCommon')) {
            // hook into Gravity Forms to trap form submissions
            add_filter('gform_currency', array($this, 'gformCurrency'));
            add_filter('gform_validation', array($this, 'gformValidation'));
            add_action('gform_after_submission', array($this, 'gformAfterSubmission'), 10, 2);
            add_filter('gform_custom_merge_tags', array($this, 'gformCustomMergeTags'), 10, 4);
            add_filter('gform_replace_merge_tags', array($this, 'gformReplaceMergeTags'), 10, 7);
        }

        if (is_admin() == true) {
            // kick off the admin handling
            new GFJeebAdmin($this);
        }
    }

    /**
     * process a form validation filter hook; if last page and has total, attempt to bill it
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @return array
     */
    public function gformValidation($data)
    {
        // make sure all other validations passed
        if ($data['is_valid']) {
            $formData = new GFJeebFormData($data['form']);

            if (false === isset($formData) || true === empty($formData)) {
                error_log('[ERROR] In GFJeebPlugin::gformValidation(): Could not create a new McryptExtension object.');
                throw new \Exception('An error occurred in the Jeeb Payment plugin: Could not create a new gformValidation object.');
            }

            // make sure form hasn't already been submitted / processed
            if ($this->hasFormBeenProcessed($data['form'])) {
                $data['is_valid'] = false;

                $formData->buyerName['failed_validation']  = true;
                $formData->buyerName['validation_message'] = $this->getErrMsg(GFJEEB_ERROR_ALREADY_SUBMITTED);
            } else if ($formData->isLastPage()) {
                // make that this is the last page of the form
                if (!$formData) {
                    $data['is_valid'] = false;

                    $formData->buyerName['failed_validation']  = true;
                    $formData->buyerName['validation_message'] = $this->getErrMsg(GFJEEB_ERROR_NO_AMOUNT);
                } else {
                    if ($formData->total > 0) {
                        $data = $this->processSinglePayment($data, $formData);
                    } else {
                        $formData->buyerName['failed_validation']  = true;
                        $formData->buyerName['validation_message'] = $this->getErrMsg(GFJEEB_ERROR_NO_AMOUNT);
                    }
                }
            }

            // if errors, send back to the customer information page
            if (!$data['is_valid']) {
                GFFormDisplay::set_current_page($data['form']['id'], $formData->buyerName['pageNumber']);
            }
        }

        return $data;
    }

    /**
     * check whether this form entry's unique ID has already been used; if so, we've already done a payment attempt.
     * @param array $form
     * @return boolean
     */
    protected function hasFormBeenProcessed($form)
    {
        global $wpdb;

        $unique_id = RGFormsModel::get_form_unique_id($form['id']);
        $sql       = "select lead_id from {$wpdb->prefix}rg_lead_meta where meta_key='gfjeeb_unique_id' and meta_value = %s";
        $lead_id   = $wpdb->get_var($wpdb->prepare($sql, $unique_id));

        return !empty($lead_id);
    }

    /**
     * get customer ID
     * @return string
     */
    protected function getCustomerID()
    {
        return $this->options['customerID'];
    }

    /**
     * process regular one-off payment
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @param GFJeebFormData $formData pre-parsed data from $data
     * @return array
     */
    protected function processSinglePayment($data, $formData)
    {
        try {
            $jeeb = new GFJeebPayment();

            if (false === isset($jeeb) || true === empty($jeeb)) {
                error_log('[ERROR] In GFJeebPlugin::processSinglePayment(): Could not create a new GFJeebPayment object.');
                throw new \Exception('An error occurred in the Jeeb Payment plugin: Could not create a new GFJeebPayment object.');
            }

            $jeeb->uid                = uniqid();
            $this->uid                = $jeeb->uid;
            $jeeb->total              = $formData->total;
            $jeeb->buyerEmail        = $formData->buyerEmail;

            $this->txResult = array (
                'payment_gateway'    => 'gfjeeb',
                'gfjeeb_unique_id' => GFFormsModel::get_form_unique_id($data['form']['id']),
            );

            $response = $jeeb->processPayment();

            $this->txResult['payment_status']   = 'New';
            $this->txResult['date_created']     = date('Y-m-d H:i:s');
            $this->txResult['payment_date']     = null;
            $this->txResult['payment_amount']   = $jeeb->total;
            $this->txResult['transaction_id']   = $jeeb->uid;
            $this->txResult['transaction_type'] = 1;
            $this->txResult['currency']         = GFCommon::get_currency();
            $this->txResult['status']           = 'Active';
            $this->txResult['payment_method']   = 'Bitcoin';
            $this->txResult['is_fulfilled']     = '0';

        } catch (GFJeebException $e) {
            $data['is_valid'] = false;
            $this->txResult   = array('payment_status' => 'Failed',);

            error_log('[ERROR] In GFJeebPlugin::processSinglePayment(): ' . $e->getMessage());

            throw $e;
        }

        return $data;
    }


    /**
     * save the transaction details to the entry after it has been created
     * @param array $data an array with elements is_valid (boolean) and form (array of form elements)
     * @return array
     */
    public function gformAfterSubmission($entry, $form)
    {
        global $wpdb;

        $formData = new GFJeebFormData($form);

        if (false === isset($formData) || true === empty($formData)) {
            error_log('[ERROR] In GFJeebPlugin::gformAfterSubmission(): Could not create a new GFJeebFormData object.');
            throw new \Exception('An error occurred in the Jeeb Payment plugin: Could not create a new GFJeebFormData object.');
        }

        if (false === empty($this->txResult)) {
            foreach ($this->txResult as $key => $value) {
                switch ($key) {
                    case 'authcode':
                        gform_update_meta($entry['id'], $key, $value);
                        break;
                    default:
                        $entry[$key] = $value;
                        break;
                }
            }

            if (class_exists('RGFormsModel') == true) {
                RGFormsModel::update_lead($entry);
            } elseif (class_exists('GFAPI') == true) {
                GFAPI::update_entry($entry);
            } else {
                throw new Exception('[ERROR] In GFJeebPlugin::gformAfterSubmission(): GFAPI or RGFormsModel won\'t update lead.');
            }

            // record entry's unique ID in database
            $unique_id = RGFormsModel::get_form_unique_id($form['id']);

            gform_update_meta($entry['id'], 'gfjeeb_transaction_id', $unique_id);

            // record payment gateway
            gform_update_meta($entry['id'], 'payment_gateway', 'gfjeeb');
        }
    }

    /**
     * add custom merge tags
     * @param array $merge_tags
     * @param int $form_id
     * @param array $fields
     * @param int $element_id
     * @return array
     */
    public function gformCustomMergeTags($merge_tags, $form_id, $fields, $element_id)
    {
        if ($fields && $this->hasFieldType($fields, 'creditcard')) {
            $merge_tags[] = array('label' => 'Transaction ID', 'tag' => '{transaction_id}');
            $merge_tags[] = array('label' => 'Auth Code', 'tag' => '{authcode}');
            $merge_tags[] = array('label' => 'Payment Amount', 'tag' => '{payment_amount}');
            $merge_tags[] = array('label' => 'Payment Status', 'tag' => '{payment_status}');
        }

        return $merge_tags;
    }

    /**
     * replace custom merge tags
     * @param string $text
     * @param array $form
     * @param array $lead
     * @param bool $url_encode
     * @param bool $esc_html
     * @param bool $nl2br
     * @param string $format
     * @return string
     */
    public function gformReplaceMergeTags($text, $form, $lead, $url_encode, $esc_html, $nl2br, $format)
    {
        if ($this->hasFieldType($form['fields'], 'buyerName')) {
            if (true === empty($this->txResult)) {
                // lead loaded from database, get values from lead meta
                $transaction_id = isset($lead['transaction_id']) ? $lead['transaction_id'] : '';
                $payment_amount = isset($lead['payment_amount']) ? $lead['payment_amount'] : '';
                $payment_status = isset($lead['payment_status']) ? $lead['payment_status'] : '';
                $authcode       = (string) gform_get_meta($lead['id'], 'authcode');
            } else {
                // lead not yet saved, get values from transaction results
                $transaction_id = isset($this->txResult['transaction_id']) ? $this->txResult['transaction_id'] : '';
                $payment_amount = isset($this->txResult['payment_amount']) ? $this->txResult['payment_amount'] : '';
                $payment_status = isset($this->txResult['payment_status']) ? $this->txResult['payment_status'] : '';
                $authcode       = isset($this->txResult['authcode']) ? $this->txResult['authcode'] : '';
            }

            $tags = array (
                '{transaction_id}',
                '{payment_amount}',
                '{payment_status}',
                '{authcode}'
            );

            $values = array (
                $transaction_id,
                $payment_amount,
                $payment_status,
                $authcode
            );

            $text = str_replace($tags, $values, $text);
        }

        return $text;
    }


    /**
     * tell Gravity Forms what currencies we can process
     * @param string $currency
     * @return string
     */
    public function gformCurrency($currency)
    {
        return $currency;
    }

    /**
     * check form to see if it has a field of specified type
     * @param array $fields array of fields
     * @param string $type name of field type
     * @return boolean
     */
    public static function hasFieldType($fields, $type)
    {
        if (true === is_array($fields)) {
            foreach ($fields as $field) {
                if (RGFormsModel::get_input_type($field) == $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * get nominated error message, checking for custom error message in WP options
     * @param string $errName the fixed name for the error message (a constant)
     * @param boolean $useDefault whether to return the default, or check for a custom message
     * @return string
     */
    public function getErrMsg($errName, $useDefault = false)
    {
        static $messages = array (
            GFJEEB_ERROR_ALREADY_SUBMITTED => 'Payment has already been submitted and processed.',
            GFJEEB_ERROR_NO_AMOUNT         => 'This form is missing products or totals',
            GFJEEB_ERROR_FAIL              => 'Error processing Jeeb transaction',
        );

        // default
        $msg = isset($messages[$errName]) ? $messages[$errName] : 'Unknown error';

        // check for custom message
        if (!$useDefault) {
            $msg = get_option($errName, $msg);
        }

        return $msg;
    }

    /**
     * get the customer's IP address dynamically from server variables
     * @return string
     */
    public static function getCustomerIP()
    {
        $plugin = self::getInstance();

        // check for remote address, ignore all other headers as they can be spoofed easily
        if (true === isset($_SERVER['REMOTE_ADDR']) && self::isIpAddress($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return '';
    }

    /**
     * check whether a given string is an IP address
     * @param string $maybeIP
     * @return bool
     */
    protected static function isIpAddress($maybeIP)
    {
        if (true === function_exists('inet_pton')) {
            // check for IPv4 and IPv6 addresses
            return !!inet_pton($maybeIP);
        }

        // just check for IPv4 addresses
        return !!ip2long($maybeIP);
    }

    /**
     * display a message (already HTML-conformant)
     * @param string $msg HTML-encoded message to display inside a paragraph
     */
    public static function showMessage($msg)
    {
        echo "<div class='updated fade'><p><strong>$msg</strong></p></div>\n";
    }

    /**
     * display an error message (already HTML-conformant)
     * @param string $msg HTML-encoded message to display inside a paragraph
     */
    public static function showError($msg)
    {
        echo "<div class='error'><p><strong>$msg</strong></p></div>\n";
    }
}

function jeeb_callback()
{
    try {
        global $wpdb;

        $postdata = file_get_contents("php://input");
        $json = json_decode($postdata, true);

        if($json['signature']==get_option("jeebSignature")){
          if($json['orderNo']){
            error_log("hey".$json['orderNo']);
            $table_name = $wpdb->prefix.'jeeb_transactions';

            $orderNo = $json['orderNo'];

            $row = $wpdb->get_results("SELECT * FROM {$table_name} WHERE `order_id` = '".$orderNo."'", ARRAY_A);
            error_log("Buyer Email : ".$row[0]['buyer_email']);
            $buyer_email = $row[0]['buyer_email'];

            // Call Jeeb
            if (get_option("jeebNetwork") == "Testnet")
            {
                $network_uri = "http://test.jeeb.io:9876/";
            }
            else
            {
                $network_uri = "https://jeeb.io/";
            }


            error_log("Entered Jeeb-Notification");
            if ( $json['stateId']== 2 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              error_log('Object : '.print_r($json, true));
            }
            else if ( $json['stateId']== 3 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              error_log('Object : '.print_r($json, true));

              // add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
              //
              // $message  = 'Thank you! Your payment has been received, but the transaction has not been confirmed on the bitcoin network. You will receive another email when the transaction has been confirmed.'.
              // '<br>Order Id:'.$json['orderNo'].
              // '<br>Invoice Amount:'.$json['requestAmount'];
              //
              // if (wp_mail($buyer_email, 'Payment Received', $message)) {
              //   error_log("Successfully sent the Email to the customer.");
              // }
              //
              // remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
            }
            else if ( $json['stateId']== 4 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              $data = array(
                "token" => $json["token"]
              );

              $data_string = json_encode($data);
              $api_key = get_option("jeebSignature");
              $url = $network_uri.'api/bitcoin/confirm/'.$api_key;
              error_log("Signature:".$api_key." Base-Url:".$network_uri." Url:".$url);

              $ch = curl_init($url);
              curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
              curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
              curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
              curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                  'Content-Type: application/json',
                  'Content-Length: ' . strlen($data_string))
              );

              $result = curl_exec($ch);
              $data = json_decode( $result , true);
              error_log("data = ".var_export($data, TRUE));


              if($data['result']['isConfirmed']){
                error_log('Payment confirmed by jeeb');

                add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
                $message  = jeeb_confimation_template($data['result']);

                if (wp_mail($buyer_email, 'Payment Confirmed', $message)) {
                  error_log("Successfully sent the Email to the customer.");
                }

                remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
              }
              else {
                error_log('Payment confirmation rejected by jeeb');
              }
            }
            else if ( $json['stateId']== 5 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

              // add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
              // $message  = 'Invoice was expired and the transaction failed.'.
              // '<br>Order Id:'.$json['orderNo'].
              // '<br>Invoice Amount:'.$json['requestAmount'];
              //
              // if (wp_mail($buyer_email, 'Invoice Expired', $message)) {
              //   error_log("Successfully sent the Email to the customer.");
              // }
              //
              // remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );

            }
            else if ( $json['stateId']== 6 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

              // add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
              // $message  = 'Invoice was over paid and the transaction was rejected by Jeeb'.
              // '<br>Order Id:'.$json['orderNo'].
              // '<br>Invoice Amount:'.$json['requestAmount'];
              //
              // if (wp_mail($buyer_email, 'Invoice Over Paid', $message)) {
              //   error_log("Successfully sent the Email to the customer.");
              // }
              //
              // remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );

            }
            else if ( $json['stateId']== 7 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

              // add_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
              // $message  = 'Invoice was partially paid and the transaction was rejected by Jeeb, Please try again.'.
              // '<br>Order Id:'.$json['orderNo'].
              // '<br>Invoice Amount:'.$json['requestAmount'];
              //
              // if (wp_mail($buyer_email, 'Invoice Under Paid', $message)) {
              //   error_log("Successfully sent the Email to the customer.");
              // }
              //
              // remove_filter( 'wp_mail_content_type', 'wpdocs_set_html_mail_content_type' );
            }
            else{
              error_log('Cannot read state id sent by Jeeb');
            }
        }
    }


    } catch (\Exception $e) {
        error_log('[Error] In GFJeebPlugin::jeeb_callback() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '".');
        throw $e;
    }
}

function wpdocs_set_html_mail_content_type() {
    return 'text/html';
}

function jeeb_confimation_template($data){
  $finalTime = date ('Y-m-d H:i:s',strtotime($data['finalizedTime']));
  $html = "<html dir=rtl lang=fa-IR>

<head>
    <title>رسید تراکنش موفق</title>
    <meta charset=\"utf-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\" />
    <style type=\"text/css\">
        /* CLIENT-SPECIFIC STYLES */
        body, table, td, a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        /* Prevent WebKit and Windows mobile changing default text sizes */
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        /* Remove spacing between tables in Outlook 2007 and up */
        img {
            -ms-interpolation-mode: bicubic;
        }
        /* Allow smoother rendering of resized image in Internet Explorer */

        /* RESET STYLES */
        img {
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        table {
            border-collapse: collapse !important;
        }

        body {
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }

        /* iOS BLUE LINKS */
        a[x-apple-data-detectors] {
            color: inherit !important;
            text-decoration: none !important;
            font-size: inherit !important;
            font-family: inherit !important;
            font-weight: inherit !important;
            line-height: inherit !important;
        }

        /* MOBILE STYLES */
        @media screen and (max-width: 525px) {

            /* ALLOWS FOR FLUID TABLES */
            .wrapper {
                width: 100% !important;
                max-width: 100% !important;
            }

            /* ADJUSTS LAYOUT OF LOGO IMAGE */
            .logo img {
                margin: 0 auto !important;
            }

            /* USE THESE CLASSES TO HIDE CONTENT ON MOBILE */
            .mobile-hide {
                display: none !important;
            }

            .img-max {
                max-width: 100% !important;
                width: 100% !important;
                height: auto !important;
            }

            /* FULL-WIDTH TABLES */
            .responsive-table {
                width: 100% !important;
            }

            /* UTILITY CLASSES FOR ADJUSTING PADDING ON MOBILE */
            .padding {
                padding: 10px 5% 15px 5% !important;
            }

            .padding-meta {
                padding: 30px 5% 0px 5% !important;
                text-align: center;
            }

            .padding-copy {
                padding: 10px 5% 10px 5% !important;
                text-align: center;
            }

            .no-padding {
                padding: 0 !important;
            }

            .section-padding {
                padding: 50px 15px 50px 15px !important;
            }

            /* ADJUST BUTTONS ON MOBILE */
            .mobile-button-container {
                margin: 0 auto;
                width: 100% !important;
            }

            .mobile-button {
                border: 0 !important;
                font-size: 16px !important;
                display: block !important;
            }
        }

        /* ANDROID CENTER FIX */
        div[style*=\"margin: 16px 0;\"] {
            margin: 0 !important;
        }
    </style>
</head>

<body>
<div>
<table class=\"m_-5854262889076256333marginFix\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\" style=\"font-family:Iransans, tahoma,'sans serif'; letter-spacing:normal; text-indent:0px; text-transform:none; word-spacing:0px; background-color:rgb(242,242,242)\">
<tbody>
<tr>
<td class=\"m_-5854262889076256333mobMargin\" bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:0px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
    &nbsp;
</td>
<td class=\"m_-5854262889076256333mobContent\" bgcolor=\"#ffffff\" align=\"center\" width=\"660\" style=\"font-family:Iransans, tahoma,'sans serif';font-size:16px;line-height:22px;color:rgb(51,51,51);font-weight:normal;\">
    <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
        <tbody>
        <tr>
            <td valign=\"top\" align=\"center\" width=\"600\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
                    <tbody>
                    <tr class=\"m_-5854262889076256333no_mobile_phone\">
                        <td bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal; padding-top:10px\"></td>
                    </tr>
                    <tr>
                        <td bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal; padding-top:10px\"></td>
                    </tr>
                    <tr>
                        <td valign=\"top\" bgcolor=\"#ffffff\" align=\"center\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                            <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\" style=\"margin-bottom:10px\">
                                <tbody dir=\"rtl\">
                                <tr valign=\"bottom\">
                                    <td valign=\"top\" align=\"center\" width=\"20\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                                        &nbsp;
                                    </td>
                                    <td align=\"right\" style=\"font-family:Iransans, tahoma,'sans serif';font-size: 11px;line-height:22px;color:rgb(51,51,51);font-weight:100;float: right;margin-top: 10px;display: inline-block;\">
                                        <span dir=\"rtl\" style=\"padding-top: 15px; padding-bottom: 10px; color: rgb(117, 117, 117); line-height: 15px\">
                                            زمان تکمیل تراکنش:
                                            <span style=\"display: inline\">".$finalTime."<span class=\"m_-5854262889076256333Apple-converted-space\">&nbsp;</span></span><span dir=\"rtl\" style=\"display: block; margin-top: -3px;\">
                                                <span style=\"display: inline\">
                                                    <br><span style=\"font-size: 11px;\">
                                                        کد پیگیری:
                                                    </span><span class=\"m_-5854262889076256333Apple-converted-space\">&nbsp;</span><a target=\"_blank\" style=\"color: rgb(0, 156, 222); font-weight: 100; text-decoration: none; font-family: Iransans, tahoma, 'sans serif'; font-size: 10px;\">".$data['referenceNo']."&nbsp;</a>
                                                </span>
                                            </span>
                                        </span>
                                    </td>

                                    <td align=\"left\" height=\"64\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal;float: left;margin-top: 10px\">
                                        <img src=\"https://jeeb.io/img/email-logo-n.png\" alt=\"Jeeb\" border=\"0\" width=\"120\" style=\"width: 100px;user-select: none;\">
                                    </td>


                                    <td valign=\"top\" align=\"center\" width=\"20\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                                        &nbsp;
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                            <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\" style=\"padding-bottom:10px; padding-top:10px; margin-bottom:20px\">
                                <tbody>
                                <tr valign=\"bottom\">
                                    <td valign=\"top\" align=\"center\" width=\"20\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                                        &nbsp;
                                    </td>
                                    <td class=\"m_-5854262889076256333ppsans\" dir=\"rtl\" valign=\"top\" style=\"font-family:pp-sans-big-light,'Noto Sans',Iransans, tahoma,'sans serif'!important; font-size:15px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                                        <div style=\"margin-top:30px; font-family:Iransans, tahoma,helvetica,sans-serif; font-size:12px; color:rgb(51,51,51)!important\">
                                            <span style=\"font-weight:100; font-family:Iransans, tahoma,helvetica,sans-serif; color:rgb(51,51,51)!important\">کاربر عزیز،</span>
                                            <br>
                                            <table>
                                                <tbody>
                                                <tr>
                                                    <td valign=\"top\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:12px; line-height:22px; color:rgb(51,51,51); font-weight:normal\">
                                                        <span style=\"font-size: 12px;font-weight:100;text-decoration:none;\">تراکنش با مشخصات ذیل با موفقیت نهایی سازی شد.</span>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                            <br>


                                            <div style=\"margin-top:5px\">
                                                <span style=\"display:inline\"></span>

                                                <div></div>
                                                <table class=\"m_-5854262889076256333CartTable\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" border=\"0\" width=\"100%\" style=\"clear:both;font-family:Iransans, tahoma,helvetica,sans-serif;font-size:11px;margin-top:20px;color:rgb(102,102,102)!important;\">
                                                    <tbody>
                                                    <tr style=\"height: 30px;\">
                                                        <td width=\"30%\" style=\"font-family:Iransans, tahoma,'sans serif';font-size: 11px;color:#555!important;font-weight:normal;border-top-width:1px;border-bottom-width:1px;border-style:solid none;border-top-color:#cccccc;border-bottom-color:#cccccc;padding:5px 10px!important;\">شماره سفارش</td>
                                                        <td align=\"right\" width=\"25%\" style=\"font-family:Iransans, tahoma,'sans serif';font-size: 11px;color:#555!important;font-weight:normal;border-top-width:1px;border-bottom-width:1px;border-style:solid none;border-top-color:#cccccc;border-bottom-color:#cccccc;padding:5px 10px!important;\">کد پیگیری</td>

                                                        <td align=\"right\" width=\"20%\" style=\"font-family:Iransans, tahoma,'sans serif';font-size: 11px;color:#555!important;font-weight:normal;border-top-width:1px;border-bottom-width:1px;border-style:solid none;border-top-color:#cccccc;border-bottom-color:#cccccc;padding:5px 10px!important;\">مبلغ واریزی</td>
                                                    </tr>
                                                    <tr width=\"40%\">
                                                        <td align=\"right\" width=\"20%\" dir=\"ltr\" style=\"font-family:Iransans, tahoma,'sans serif';font-size:12px;color:rgb(51,51,51);font-weight:normal;border-bottom-width:1px;border-bottom-color: #cccccc;padding:10px;\">
                                                            ".$data['orderNo']."
                                                        </td>
                            <td align=\"right\" width=\"20%\" dir=\"ltr\" style=\"font-family:Iransans, tahoma,'sans serif';font-size:12px;color:rgb(51,51,51);font-weight:normal;border-bottom-width:1px;border-bottom-color: #cccccc;padding:10px;\">
                                                            ".$data['referenceNo']."
                                                        </td>
                                                        <td align=\"right\" width=\"20%\" dir=\"ltr\" style=\"font-family:Iransans, tahoma,'sans serif';font-size:12px;color:rgb(51,51,51);font-weight:normal;border-bottom-width:1px;border-bottom-color: #cccccc;padding:10px;\">
                                                            ".$data['value']." BTC
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>

                                                <br>


                                                <br>
                                            </div>
                                            <span style=\"font-weight:bold; color:rgb(68,68,68)\"></span>
                                            <span></span>
                                        </div>
                                    </td>
                                    <td valign=\"top\" align=\"center\" width=\"20\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px;  color:rgb(51,51,51); font-weight:normal\">
                                        &nbsp;
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
    <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
        <tbody>
        <tr>
            <td valign=\"top\" align=\"center\" width=\"600\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px;  color:rgb(51,51,51); font-weight:normal\">
                <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
                    <tbody>
                    <tr>
                        <td bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px;  color:rgb(51,51,51); font-weight:normal; padding-top:20px\"></td>
                    </tr>
                    <tr>
                        <td valign=\"top\" bgcolor=\"#f2f2f2\" align=\"center\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px;  color:rgb(51,51,51); font-weight:normal\">

                            <table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
                                <tbody>
                                <tr valign=\"bottom\">

                                    <td style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px;  color:rgb(51,51,51); font-weight:normal\">
                                        <span style=\"font-family:Iransans, tahoma,'sans serif'; font-size:13px\">
                                            <table id=\"m_-5854262889076256333emailFooter\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\" style=\"padding-top:20px;font-style:normal;font-weight:normal;font-size: 11px;line-height:19px;text-align: center;font-family:Iransans, tahoma,Verdana,Helvetica,sans-serif;color:rgb(41,41,41);\">
                                                <tbody>
                                                <tr>
                                                    <td dir=\"rtl\" style=\"font-family:Iransans, tahoma,'sans serif';color: rgb(123, 123, 123);font-weight: 100;padding:0px 20px\">
                                                        <p> خواهشمندیم جهت هرگونه پرسش یا پیشنهاد با تیم پشتیبانی، تماس حاصل فرمایید .<br /> بسیار خرسند خواهیم شد تا با تجربیات \"جیب\" شما را راهنمایی کنیم.</p>
                                                        <p style=\"color: #d2d2d2;\"><a href=\"https://jeeb.io/documentation\" style=\"text-decoration:none;color: #2170c2;\" target=\"_blank\">مستندات</a> |      <a href=\"https://jeeb.io/rules\" style=\"text-decoration:none;color: #2170c2;\" target=\"_blank\">قوانین سایت</a> | <a href=\"https://jeeb.io/faq\" style=\"text-decoration:none;color: #2170c2;\" target=\"_blank\">سوالات متداول</a> | <a href=\"https://jeeb.io/contactus\" style=\"text-decoration:none;color: #2170c2;\" target=\"_blank\">تماس با ما</a> </p>
                                                        <p>تمامی حقوق معنوی برای ™Jeeb محفوظ است.</p>

                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </span>
                                    </td>

                                </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
</td>
<td class=\"m_-5854262889076256333mobMargin\" bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:0px;  color:rgb(51,51,51); font-weight:normal\">
    &nbsp;
</td>
</tr>
<tr>
    <td bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal; padding-top:10px\"></td>
</tr>
<tr>
    <td bgcolor=\"#f2f2f2\" style=\"font-family:Iransans, tahoma,'sans serif'; font-size:16px; line-height:22px; color:rgb(51,51,51); font-weight:normal; padding-top:10px\"></td>
</tr>
</tbody>
</table>
</div>

</body>

</html>";

return $html;
}

add_action('init', 'jeeb_callback');
