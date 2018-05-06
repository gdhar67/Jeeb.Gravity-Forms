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

            $network_uri = "https://core.jeeb.io/api/" ;


            error_log("Entered Jeeb-Notification");
            if ( $json['stateId']== 2 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              error_log('Object : '.print_r($json, true));
            }
            else if ( $json['stateId']== 3 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              error_log('Object : '.print_r($json, true));

            }
            else if ( $json['stateId']== 4 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
              $data = array(
                "token" => $json["token"]
              );

              $data_string = json_encode($data);
              $api_key = get_option("jeebSignature");
              $url = $network_uri.'payments/' . $api_key . '/confirm';
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

              }
              else {
                error_log('Payment confirmation rejected by jeeb');
              }
            }
            else if ( $json['stateId']== 5 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

            }
            else if ( $json['stateId']== 6 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

            }
            else if ( $json['stateId']== 7 ) {
              error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);

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

add_action('init', 'jeeb_callback');
