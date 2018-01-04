<?php


/**
 * Class for handling Jeeb payment
 *
 * @link https://jeeb.com/bitcoin-payment-gateway-api
 */
class GFJeebPayment
{
    public $uid;            // Displays unique id
    public $total;          // Displays Total
    public $buyer_email;    // Displays Customer's EmaiL

    /**
     * Writes $contents to system error logger.
     *
     * @param mixed $contents
     * @throws Exception $e
     */
    public function error_log($contents)
    {
        if (false === isset($contents) || true === empty($contents)) {
            return;
        }

        if (true === is_array($contents)) {
            $contents = var_export($contents, true);
        } else if (true === is_object($contents)) {
            $contents = json_encode($contents);
        }

        error_log($contents);
    }

    public function convertIrrToBtc($url, $amount, $signature) {

        // return Jeeb::convert_irr_to_btc($url, $amount, $signature);
        $ch = curl_init($url.'api/convert/'.$signature.'/'.$amount.'/irr/btc');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json')
      );

      $result = curl_exec($ch);
      $data = json_decode( $result , true);
      error_log('data = '.$data["result"]);
      // Return the equivalent bitcoin value acquired from Jeeb server.
      return (float) $data["result"];

      }


      public function createInvoice($url, $amount, $options = array(), $signature) {

          $post = json_encode($options);

          $ch = curl_init($url.'api/bitcoin/issue/'.$signature);
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
          curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
              'Content-Length: ' . strlen($post))
          );

          $result = curl_exec($ch);
          $data = json_decode( $result , true);
          error_log('data = '.$data['result']['token']);

          return $data['result']['token'];

      }

      public function redirectPayment($url, $token) {
        error_log("Entered into auto submit-form");
        // Using Auto-submit form to redirect user with the token
        echo "<form id='form' method='post' action='".$url."invoice/payment'>".
                "<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
               "</form>".
               "<script type='text/javascript'>".
                    "document.getElementById('form').submit();".
               "</script>";
      }

    /**
     * Process a payment
     */
    public function processPayment()
    {
      global $wpdb;
            if (true === empty(get_option('jeebRedirectURL'))) {
                update_option('jeebRedirectURL', get_site_url());
            }

            // price
            $price = number_format($this->total, 2, '.', '');

            $baseUri = (get_option('jeebNetwork') == 'Testnet') ? "http://test.jeeb.io:9876/" : "https://jeeb.io/" ;
            $signature = get_option('jeebSignature');
            $callBack  = get_option('jeebRedirectURL');
            $notification = get_option('siteurl').'/?jeeb_callback=true';
            $order_total = $price;

            error_log($this->uid." ".$baseUri." ".$signature." ".$callBack." ".$notification);
            error_log("Cost = ". $price);

            $btc = $this->convertIrrToBtc($baseUri, $order_total, $signature);

            $params = array(
              'orderNo'          => $this->uid,
              'requestAmount'    => (float) $btc,
              'notificationUrl'  => $notification,
              'callBackUrl'       => $callBack,
              'allowReject'      => get_option('jeebNetwork')=="Testnet" ? false : true
            );

            $token = $this->createInvoice($baseUri, $btc, $params, $signature);

            $table_name = $wpdb->prefix.'jeeb_transactions';

            $data = array(
                'order_id' => $this->uid,
                'buyer_email' => $this->buyerEmail,
                'token'  => $token
            );

            $wpdb->insert($table_name, $data);

            $this->redirectPayment($baseUri, $token);

    }

}
