<?php


/**
 * Options form input fields
 */
class GFJeebOptionsForm
{

    public $jeebSignature    = '';
    public $jeebRedirectURL  = '';
    public $jeebNetwork      = '';
    // public $jeebBase         = '';
    // public $jeebLang         = '';
    // public $jeebBase         = '';
    // public $jeebBtc          = '';
    // public $jeebXmr          = '';
    // public $jeebXrp          = '';
    // public $jeebBch          = '';
    // public $jeebLtc          = '';
    // public $jeebEth          = '';
    // public $jeebTestBtc      = '';

    /**
     * initialise from form post, if posted
     */
    public function __construct()
    {
        if (self::isFormPost()) {
            $this->jeebSignature    = self::getPostValue('jeebSignature');
            $this->jeebRedirectURL  = self::getPostValue('jeebRedirectURL');
            $this->jeebNetwork      = self::getPostValue('jeebNetwork');
            $this->jeebBase         = self::getPostValue('jeebBase');
            $this->jeebBtc          = self::getPostValue('jeebBtc');
            $this->jeebXmr          = self::getPostValue('jeebXmr');
            $this->jeebXrp          = self::getPostValue('jeebXrp');
            $this->jeebBch          = self::getPostValue('jeebBch');
            $this->jeebLtc          = self::getPostValue('jeebLtc');
            $this->jeebEth          = self::getPostValue('jeebEth');
            $this->jeebTestBtc      = self::getPostValue('jeebTestBtc');
            $this->jeebLang         = self::getPostValue('jeebLang');
        }
    }

    /**
     * Is this web request a form post?
     *
     * Checks to see whether the HTML input form was posted.
     *
     * @return boolean
     */
    public static function isFormPost()
    {
        return (bool)($_SERVER['REQUEST_METHOD'] == 'POST');
    }

    /**
     * Read a field from form post input.
     *
     * Guaranteed to return a string, trimmed of leading and trailing spaces, slashes stripped out.
     *
     * @return string
     * @param string $fieldname name of the field in the form post
     */
    public static function getPostValue($fieldname)
    {
        return isset($_POST[$fieldname]) ? stripslashes(trim($_POST[$fieldname])) : '';
    }

    /**
     * Validate the form input, and return error messages.
     *
     * Return a string detailing error messages for validation errors discovered,
     * or an empty string if no errors found.
     * The string should be HTML-clean, ready for putting inside a paragraph tag.
     *
     * @return string
     */
    public function validate()
    {
        $errmsg = '';

        if (false === isset($this->jeebRedirectURL) || strlen($this->jeebRedirectURL) <= 0) {
            $errmsg .= "# Please enter a Redirect URL.<br/>\n";
        }

        if (false === isset($this->jeebSignature) || strlen($this->jeebSignature) <= 0) {
            $errmsg .= "# Please enter your Signature.<br/>\n";
        }

        if (false === isset($this->jeebBase) || strlen($this->jeebBase) <= 0) {
            $errmsg .= "# Please select a base currency.<br/>\n";
        }

        return $errmsg;
    }
}

/**
 * Options admin
 */
class GFJeebOptionsAdmin
{

    private $plugin;           // handle to the plugin object
    private $menuPage;         // slug for admin menu page
    private $scriptURL = '';
    private $frm;              // handle for the form validator

    /**
     * @param GFJeebPlugin $plugin handle to the plugin object
     * @param string $menuPage URL slug for this admin menu page
     */
    public function __construct($plugin, $menuPage, $scriptURL)
    {
        $this->plugin    = $plugin;
        $this->menuPage  = $menuPage;
        $this->scriptURL = $scriptURL;

        wp_enqueue_script('jquery');
    }

    /**
     * process the admin request
     */
    public function process()
    {
        $this->frm = new GFJeebOptionsForm();

        if (false === isset($this->frm) || true === empty($this->frm)) {
            error_log('[ERROR] In GFJeebOptionsAdmin::process(): Could not create a new GFJeebOptionsForm object.');
            throw new \Exception('An error occurred in the Jeeb Payment plugin: Could not create a new GFJeebOptionsForm object.');
        }

        if ($this->frm->isFormPost()) {
            check_admin_referer('save', $this->menuPage . '_wpnonce');

            $errmsg = $this->frm->validate();

            if (true === empty($errmsg)) {
                update_option('jeebSignature', $this->frm->jeebSignature);
                update_option('jeebRedirectURL', $this->frm->jeebRedirectURL);
                update_option('jeebNetwork', $this->frm->jeebNetwork);
                update_option('jeebBase', $this->frm->jeebBase);
                update_option('jeebLang', $this->frm->jeebLang);
                update_option('jeebBtc', $this->frm->jeebBtc);
                update_option('jeebXmr', $this->frm->jeebXmr);
                update_option('jeebXrp', $this->frm->jeebXrp);
                update_option('jeebLtc', $this->frm->jeebLtc);
                update_option('jeebEth', $this->frm->jeebEth);
                update_option('jeebBch', $this->frm->jeebBch);
                update_option('jeebTestBtc', $this->frm->jeebTestBtc);


                $this->plugin->showMessage(__('Options saved.'));
            } else {
                $this->plugin->showError($errmsg);
            }
        } else {
            // initialise form from stored options
            $this->frm->jeebNetwork     = get_option('jeebNetwork');
            $this->frm->jeebRedirectURL = get_option('jeebRedirectURL');
            $this->frm->jeebSignature   = get_option('jeebSignature');
            $this->frm->jeebBase        = get_option('jeebBase');
            $this->frm->jeebLang        = get_option('jeebLang');
            $this->frm->jeebBtc         = get_option('jeebBtc');
            $this->frm->jeebXmr         = get_option('jeebXmr');
            $this->frm->jeebXrp         = get_option('jeebXrp');
            $this->frm->jeebLtc         = get_option('jeebLtc');
            $this->frm->jeebEth         = get_option('jeebEth');
            $this->frm->jeebBch         = get_option('jeebBch');
            $this->frm->jeebTestBtc     = get_option('jeebTestBtc');
        }

        require GFJEEB_PLUGIN_ROOT . 'views/admin-settings.php';
    }
}
