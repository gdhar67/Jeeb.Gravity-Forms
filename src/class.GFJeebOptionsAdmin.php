<?php


/**
 * Options form input fields
 */
class GFJeebOptionsForm
{

    public $jeebSignature = '';
    public $jeebRedirectURL      = '';
    public $jeebNetwork      = '';

    /**
     * initialise from form post, if posted
     */
    public function __construct()
    {
        if (self::isFormPost()) {
            $this->jeebSignature = self::getPostValue('jeebSignature');
            $this->jeebRedirectURL      = self::getPostValue('jeebRedirectURL');
            $this->jeebNetwork      = self::getPostValue('jeebNetwork');
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

                $this->plugin->showMessage(__('Options saved.'));
            } else {
                $this->plugin->showError($errmsg);
            }
        } else {
            // initialise form from stored options
            $this->frm->jeebNetwork = get_option('jeebNetwork');
            $this->frm->jeebRedirectURL = get_option('jeebRedirectURL');
            $this->frm->jeebSignature = get_option('jeebSignature');
        }

        require GFJEEB_PLUGIN_ROOT . 'views/admin-settings.php';
    }
}
