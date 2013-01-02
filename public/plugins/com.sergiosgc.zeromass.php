<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 foldmethod=marker: */

class ZeroMassException extends Exception { }
class ZeroMassStartupException extends ZeroMassException { }
class ZeroMassNotFoundException extends ZeroMassException { }

class ZeroMass {
    public static $singleton = null;
    public $callbacks = array();
    public $apiSlots = array();
    public $privateDir = null;
    public $publicDir = null;
    public $pluginDir = null;
    protected $exceptionRecurseSemaphore = false;
    public function __construct() {/*{{{*/
        ZeroMass::$singleton = $this;
        /*#
         * Callback that occurs at the very start of ZeroMass application flow.
         *
         * At this point, no plugin is yet loaded. This is the very first callback.
         */
        \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.before__construct');
        /*#
         * Allow for the application root dir to be altered
         *
         * The application root directory is usually one level above the 
         * application public directory, so that public and private directories
         * may live within the application filespace
         *
         * @param string Full path to the application root directory
         * @return string Full path to the application root directory
         */
        $this->appRootDir = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.appRootDir', realpath(dirname(dirname(dirname(dirname(__FILE__))))));
        /*#
         * Allow for the application public dir to be altered
         *
         * The application public directory is usually a directory named `public`
         * inside the application root directory.
         *
         * @param string Full path to the application public directory
         * @return string Full path to the application public directory
         */
        $this->publicDir = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.appPublicDir', realpath($this->appRootDir . '/public'));
        /*#
         * Allow for the application private dir to be altered
         *
         * The application private directory is usually a directory named `private`
         * inside the application root directory.
         *
         * @param string Full path to the application private directory
         * @return string Full path to the application private directory
         */
        $this->privateDir = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.appPrivateDir', realpath($this->appRootDir . '/private'));
        /*#
         * Allow for the application plugin dir to be altered
         *
         * The application plugin directory is usually a directory named `plugins`
         * inside the application plugin directory.
         *
         * @param string Full path to the application plugin directory
         * @return string Full path to the application plugin directory
         */
        $this->pluginDir = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.appPluginDir', realpath(dirname(dirname(__FILE__)) . '/plugins'));

        if ($this->pluginDir === false) throw new ZeroMassStartupException('ZeroMass plugin directory not found. Expected at: ' . $this->publicDir . '/plugins');
        $this->loadPlugins();
        /*#
         * Callback that occurs just before the end of the ZeroMass constructor.
         */
        \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.after__construct');
    }/*}}}*/
    public static function getInstance() {/*{{{*/
        if (is_null(ZeroMass::$singleton)) ZeroMass::$singleton = new ZeroMass();
        return ZeroMass::$singleton;
    }/*}}}*/
    public function loadPlugins() {/*{{{*/
        $pluginDirHandle = opendir($this->pluginDir);
        if ($pluginDirHandle === false) throw new ZeroMassStartupException('Unable to open plugin directory: ' . $this->pluginDir);
        while (($file = readdir($pluginDirHandle)) !== false) {
            if ($file == '.' || $file == '..') continue;

            $fullPath = $this->pluginDir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                $tentativePluginFile = $fullPath . DIRECTORY_SEPARATOR . $file . '.php';
                if (is_file($tentativePluginFile) && is_readable($tentativePluginFile)) require_once($tentativePluginFile);
            } else {
                if (strlen($file) > 4 && substr($file, -4) == '.php') require_once($fullPath);
            }
        }
        try {
            /*#
             * Allow plugins to initalize themselves
             *
             * This is the typical hook for plugins to initialize themselves and
             * fire hooks annoucing the availability of their services. For example,
             * a database plugin would likely connect to the database and fire a 
             * `com.example.db.ready` hook.
             */
            \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.pluginInit');
        } catch (Exception $e) {
            /**
             * An exception was thrown when initializing plugins
             *
             * This hook allows plugins to handle exceptions thrown when other plugins
             * initialize themselves during processing of the 
             * `com.sergiosgc.zeromass.pluginInit` hook.
             *
             * @param Exception The exception that was thrown
             * @return mixed Either the exception, if it was unhandled, or false if it is to be considered handled
             */
            $e = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.pluginInit.exception', $e);
            if ($e) throw new ZeroMassException(null, 0, $e);
        }
    }/*}}}*/
    public function register_callback($tag, $callable, $priority = 10) {/*{{{*/
        if (!is_callable($callable)) throw new ZeroMassException('Non-callable passed as $callable argument');
        if (!isset($this->callbacks[$tag])) $this->callbacks[$tag] = array();
        if (!isset($this->callbacks[$tag][$priority])) $this->callbacks[$tag][$priority] = array();
        $this->callbacks[$tag][$priority][] = $callable;
        ksort($this->callbacks[$tag], SORT_NUMERIC);
    }/*}}}*/
    public function do_callback_array($tag, $args) {/*{{{*/
        array_unshift($args, $tag);
        return call_user_func_array(array($this, 'do_callback'), $args);
    }/*}}}*/
    public function do_callback($tag) {/*{{{*/
        $arguments = func_get_args();
        array_shift($arguments); // Get rid of the $tag argument
        $result = count($arguments) > 0 ? $arguments[0] : null;
        if (!isset($this->callbacks[$tag])) return $result;
        foreach ($this->callbacks[$tag] as $priority => $callbackArray) foreach ($callbackArray as $callback) {
            $arguments[0] = $result;
            try {
                $result = call_user_func_array($callback, $arguments);
            } catch (\Exception $e) {
                if ($this->exceptionRecurseSemaphore) throw new ZeroMassException($e);
                $exceptionArgs = $arguments;
                array_unshift($exceptionArgs, $tag);
                array_unshift($exceptionArgs, $e);
                $this->exceptionRecurseSemaphore = true;
                /*#
                 * A callback threw an exception. Allow plugins to handle the exception.
                 *
                 * If a plugin can handle the exception, it should do so by
                 * filtering out the exceptio argument and replacing it with
                 * a result that can be used to continue processing the hook.
                 *
                 * Beyond the exception and the original hook tag, this hook 
                 * receives all arguments passed on to the original hook
                 *
                 * @param Exception The thrown exception
                 * @return mixed Either an exception or a result to be used to continue processing
                 */
                $e = \ZeroMass::getInstance()->do_callback_array('com.sergiosgc.zeromass.hook.exception', $exceptionArgs);
                $this->exceptionRecurseSemaphore = false;
                if ($e instanceof \Exception) throw new ZeroMassException($e);
                $result = $e;
            }
        }
        return $result;
    }/*}}}*/

    public function unregister_callback($tag, $callable, $priority = 10) {/*{{{*/
        if (!is_callable($callable)) throw new ZeroMassException('Non-callable passed as $callable argument');

        if (!isset($this->callbacks[$tag])) return;
        if (!isset($this->callbacks[$tag][$priority])) return;
        foreach (array_reverse(array_keys($this->callbacks[$tag][$priority])) as $index) {
            if ($this->callbacks[$tag][$priority][$index] == $callable) unset($this->callbacks[$tag][$priority][$index]);
        }
    }/*}}}*/
    public function unregister_all_callbacks($tag, $priority = false) {/*{{{*/
        if (!isset($this->callbacks[$tag])) return;

        if ($priority === false) {
            unset($this->callbacks[$tag]);
        } else {
            if (!isset($this->callbacks[$tag][$priority])) return;
            unset($this->callbacks[$tag][$priority]);
            if (count($this->callbacks[$tag]) == 0) unset($this->callbacks[$tag]);
        }
    }/*}}}*/

    public function answerPage() {/*{{{*/
        $handled = false;
        /*#
         * An HTTP request for a page has been received. Allow plugins to answer the request.
         *
         * This hook has no arguments defining the request, since all of the information is
         * available on the superglobals ($\_REQUEST, $\_SERVER, etc.). 
         *
         * The only argument of the hook is a boolean, stating if the request has already 
         * been fulfilled. It is expected that plugins that choose to handle the request 
         * return true. Similarly, it is expected that plugins do not handle the request if 
         * it has been fulfilled already by another plugin (if the received boolean argument 
         * is true)
         *
         * @param boolean True iff the request has already been handled
         * @return boolean True iff the request is to be considered handled.
         */
        try {
            $handled = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.answerPage', $handled);
        } catch (Exception $e) {
            /**
             * An exception was thrown when handling the request
             *
             * This hook allows plugins to handle exceptions thrown when other plugins handle a request
             * during processing of the `com.sergiosgc.zeromass.answerPage` hook.
             *
             * @param Exception The exception that was thrown
             * @return mixed Either the exception, if it was unhandled, or false if it is to be considered handled
             */
            $e = \ZeroMass::getInstance()->do_callback('com.sergiosgc.zeromass.answerPage.exception', $e);
            if ($e) throw new ZeroMassException(null, 0, $e);
        }
    }/*}}}*/
}
ZeroMass::getInstance();
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) == realpath(__FILE__)) {
    ZeroMass::getInstance()->answerPage();
}
/*#
 * ZeroMass Core
 *
 * This is not really a plugin. It is the core of ZeroMass, responsible for 
 * loading all plugins and for the basic application flow.
 *
 * # Usage summary
 *
 * ZeroMass is a bottom software layer for web application development in PHP.
 * It sits right on top of PHP, with zero dependencies, providing an 
 * application loading mechanism, a hook system and a minimalistic application
 * execution flow.  It is an unix-philosophy approach to web application 
 * development, performing a very limited set of features extremely well, and
 * providing communication points where the next layer of software sits 
 * _without abstraction leakage_.
 *
 * Do not let the minimalistic approach fool you. A single Lego piece also has 
 * very limited functionality. 
 *
 * This usage summary is written towards plugin developers, teaches how to
 * install plugins into the application, and how the application execution 
 * flows using the hook system.
 *
 * ## Features
 *
 * ZeroMass provides two, and only two features:
 * 
 * - A plugin loading system
 * - A hook system for software component decoupling
 *
 * ## Plugin loading
 *
 * ZeroMass is pretty minimalistic. Plugin loading follows that philosophy.
 * ZeroMass will look for plugins in the /plugins/ folder, relative to the
 * webapp public directory. It will load (require\_once) all plugins found
 * there.
 *
 * ZeroMass will load one file per plugin. A plugin can be either composed of 
 * a single PHP file, or can be composed of a set of files in a directory.
 *
 * ### Single file plugins
 *
 * Single file plugins are the simplest. They are just a single file, 
 * dropped in the plugins directory. ZeroMass will just require\_once the file.
 * If your plugin file is `com.example.plugin.php`, just drop it in 
 * `plugins/com.example.plugin.php`. It will be loaded.
 *
 * ### Multi-file plugins
 *
 * A multi-file plugin isn't a lot more complicated. Instead of having a 
 * single-file, the plugin must be contained in a directory, and that directory
 * dropped in the plugins directory. ZeroMass will look for a PHP file, inside
 * that directory, with the same name as the directory. For example, if your 
 * plugin directory is
 *
 *     com.example.plugin
 *
 * ZeroMass will load
 * 
 *     com.example.plugin/com.example.plugin.php
 * 
 * No other file will be loaded, so the plugin must require\_once what it needs
 * in there. Again, reinforcing the expected behaviour, lazy-load any 
 * dependencies, so that plugin loading is a safe operation _under any 
 * circumstance_.
 *
 * ### Plugin format
 *
 * There aren't many requirements for plugins to play nice with ZeroMass. Nevertheless, 
 * a couple characteristics are expected:
 *
 * 1. Plugins should be namespaced
 * 2. Plugin loading must be safe as no error checking takes place during loading
 *
 * It is expected that plugins be namespaced, using 
 * [PHP namespaces](http://php.net/manual/en/language.namespaces.php), so ZeroMass does 
 * not have to deal with global namespace collisions. The 
 * [Java package namespacing](http://docs.oracle.com/javase/tutorial/java/package/namingpkgs.html) 
 * style is a great method: use a domain under the author control, and name the plugin 
 * using the reversed domain. For example, if your plugin is named `foobar` and you own 
 * the domain `example.com`, name the plugin `com.example.foobar` and use the 
 * `com\example\foobar` PHP namespace.
 *
 * It is also expected that plugin loading (require\_once of the plugin file) is safe.
 * Namely, no dependency checking occurs.
 * 
 * For example, an Object Relational Mapping (ORM) plugin may require a Database plugin for
 * database access. ZeroMass makes no effort towards loading the Database plugin before
 * the ORM plugin. Dependency handling should be done by the plugins themselves, using the 
 * hook system and the expected flow of events during application loading. 
 *
 * In summary, when initializing your plugin, don't expect any functionality other than 
 * ZeroMass itself to be present. Hook the plugin to relevant events in the application 
 * flow, as will be explained further down. Don't set the plugin to cause an error during 
 * plugin loading. Plugin loading should be a completely safe operation, in any circumstance.
 *
 * ## Hook system
 * 
 * The hook system is a software component decoupling mechanism, inspired by 
 * [Aspect Oriented Programming](http://en.wikipedia.org/wiki/Aspect-oriented_programming).
 * It is composed of two methods: `ZeroMass::register_callback($tag, $callable, $priority)`
 * and `ZeroMass::do_callback($tag)`.
 *
 * ### Hook naming
 *
 * Hooks are identified by their tag, the first parameter in the two functions.
 * Much as in package naming, to avoid collisions, the hook name must be
 * namespaced. Use the package name, with dots (.) instead of backslashes (\\) 
 * as a prefix for your hook names. If your plugin lives under the 
 * `com\example\foo` namespace, have all of the plugin hooks begin with 
 * `com.example.foo.`. Examples: `com.example.foo.bar`, `com.example.foo.init`,
 * etc.
 *
 * ### Firing hooks
 *
 * Plugin developers are expected to fire `do_callback` whenever it is reasonable to expect
 * other plugins may be interested in knowing about application state change, or whenever it 
 * is reasonable to expect other plugins may be interested in manipulating data being 
 * processed. Let's use an example for better description:
 *
 * Imagine a database plugin, in the process of performing a database connection:
 *
 *     namespace com\example\db;
 *     class DB {
 *         ...
 *         protected function connect() {
 *             ...
 *             $dsn = $this->getDSN();
 *             $dsn = \ZeroMass::getInstance()->do_callback('com.example.db.dsn', $dsn);
 *             $this->dbHandle = new PDO($dsn);
 *             \ZeroMass::getInstance()->do_callback('com.example.db.connected');
 *             ...
 *         }
 *
 * There are two calls to `do_callback`, exemplifying the two reasons for firing a hook. In 
 * the first case, the plugin is allowing other plugins to change the 
 * [DSN](http://php.net/manual/en/pdo.construct.php), perhaps adding authentication information
 * or selecting the closest database server slave, or for any other reason. This is, in fact, the
 * beauty of Aspect Oriented Programming. You don't need to fully specify all use cases, 
 * just provide hooks (Join Points in AOP parlance) where extra functionality may be added 
 * later on.
 *
 * Note that you may pass extra parameters to `do_callback`. All parameters 
 * after the `$tag` will be passed to the hook handlers.
 *
 * In the second case, the plugin is announcing that the database is now available. Other plugins
 * may then connect here to do any kind of tasks: database schema upgrades, logging, cache
 * refresh or, again, any kind of extra functionality not considered when writing the database plugin.
 *
 * ### Receiving hooks
 *
 * On the other end, plugins wishing to act on the hooks fired by other plugins
 * register using `register_callback`. If a plugin passes parameters when 
 * calling `do_callback`, it is expected that handlers return the first 
 * parameter. If no parameters are passed, the return value is ignored.
 *
 * If a hook is fired with the intent of allowing data to be changed by 
 * plugins, this data will be present in the first argument received by the 
 * hook handler. It is expected that the hook handler returns this data (be
 * it unchanged or after modification).
 *
 * Again, let's use the database example to exemplify usage. Imagine a plugin 
 * responsible for connecting the webapp to the closest database slave:
 *
 *     namespace com\example\db;
 *     class SlaveManager {
 *         ...
 *         public function __construct() {
 *             ...
 *             \ZeroMass::getInstance()->register_callback('com.example.db.dsn', array($this, 'setDSNSlave'));
 *             ...
 *         } 
 *         public function setDSNSlave($dsn) {
 *             // The DSN looks like "something;host=host_we_want_to_replace;something_else"
 *             preg_match('_^(.*[;:]host=)[^;]*(.*)_', $dsn, $matches);
 *             $preHost = $matches[1];
 *             $postHost = $matches[2];
 *             return $preHost . $this->getClosestServer() . $postHost;
 *         }
 *
 * The above example is filtering the DSN parameter, changing the host in the
 * DSN.
 *
 * Hooks may also be just annoucements of events, like in the following 
 * example:
 *
 *     namespace com\example\db;
 *     class SchemaUpgrader {
 *         ... 
 *         public function __construct() {
 *             ...
 *             \ZeroMass::getInstance()->register_callback('com.example.db.connected', array($this, 'checkSchema'));
 *             ...
 *         } 
 *         public function checkSchema() {
 *             if ($this->readDatabaseVersion() != $this->currentDatabaseVersion) $this->upgradeDatabase();
 *         }
 *
 * Here, the plugin hooks into the database available hook to perform upgrades to the schema if necessary. Again, note that 
 * the original database plugin need not take into account these extra features. It may focus on its core service
 * and relegate minor functionality to code written later, and placed into production only if needed.
 *
 * ## Basic application flow
 *
 * All of this is very dandy, but how do you actually produce a page with 
 * ZeroMass? Unsurprisingly, you need a plugin and you need to hook up to
 * relevant hooks.
 *
 * ZeroMass execution goes something like this:
 *
 *     ┌────────────────────────────────────────────────────────────────────────┐
 *     │ Create the ZeroMass singleton                                          │
 *     └────────────────────────────────────────────────────────────────────────┘
 *                                        ↓
 *     ┌────────────────────────────────────────────────────────────────────────┐
 *     │ require_once all plugins                                               │
 *     └────────────────────────────────────────────────────────────────────────┘
 *                                        ↓
 *     ┌────────────────────────────────────────────────────────────────────────┐
 *     │ Fire com.sergiosgc.zeromass.pluginInit                                 │
 *     └────────────────────────────────────────────────────────────────────────┘
 *                                        ↓
 *     ┌────────────────────────────────────────────────────────────────────────┐
 *     │ Fire com.sergiosgc.zeromass.answerPage with $handled boolean parameter │
 *     └────────────────────────────────────────────────────────────────────────┘
 *                                        ↓
 *     ┌────────────────────────────────────────────────────────────────────────┐
 *     │ Throw an exception if the page was not handled                         │
 *     └────────────────────────────────────────────────────────────────────────┘
 *
 * Now, you need a plugin, which is just a file on the `public/plugins` 
 * directory, named after the plugin. We'll name the plugin `com.example.hello`,
 * so create a file named `com.example.hello.php` with the skeleton code for 
 * the plugin:
 *
 *     <?php
 *     namespace com\example\hello;
 *     class HelloWorld {
 *     }
 *
 *     new HelloWorld();
 *
 * This example is object-oriented, so we create a class for the plugin and 
 * create one instance of it. This code has no possibility of raising an error,
 * as per requested in this documentation.
 *
 * Now, we take the constructor opportunity to hook into relevant hooks. This
 * is a very simple example, so we just need to hook into 
 * `com.sergiosgc.zeromass.answerPage`. The code becomes:
 *
 *     <?php
 *     namespace com\example\hello;
 *     class HelloWorld {
 *         public function __construct() {
 *             \ZeroMass::getInstance()->register_callback(
 *                 'com.sergiosgc.zeromass.answerPage', 
 *                 array($this, 'hello')
 *             );
 *         }
 *     }
 *
 *     new HelloWorld();
 *
 * And now we need to add the `hello` method, otherwise ZeroMass will throw an hissy fit:
 *
 *     <?php
 *     namespace com\example\hello;
 *     class HelloWorld {
 *         public function __construct() {
 *             \ZeroMass::getInstance()->register_callback(
 *                 'com.sergiosgc.zeromass.answerPage', 
 *                 array($this, 'hello')
 *             );
 *         }
 *         public function hello($answered) {
 *             if ($answered) return $answered;
 *             if ($_SERVER['REQUEST_URI'] != '/') return $answered;
 *     ?>
 *     <!doctype html>
 *     <html>
 *      <head>
 *       <title>Hello World</title>
 *      </head>
 *      <body>
 *       Hello World
 *      </body>
 *     </html>
 *     <?php
 *             return true;
 *         }
 *     }
 *
 *     new HelloWorld();
 *
 * `hello` produces the output, but also takes care of the `$answered` argument. 
 * The contract defined in the hook is that `$answered` is true if some other
 * plugin already answered the page. So, if it is true, `hello()` does nothing.
 * Then, we check the requested URI, so that we answer only the `/` URI and if
 * we're supposed to handle the page, produce the output and return true 
 * (signalling that the page request is handled).
 *
 * ### Proper plugin initialization
 *
 * Remember that requiring the plugin must be a 100% safe operation? What 
 * about initialization tasks that may cause errors? 
 *
 * The proper way to initialize a plugin is to use the constructor to 
 * hook into relevant hooks, and use the `com.sergiosgc.zeromass.pluginInit`
 * hook for operations that may result in errors. Continuing with the hello
 * world example:
 *
 *     <?php
 *     namespace com\example\hello;
 *     class HelloWorld {
 *         public function __construct() {
 *             \ZeroMass::getInstance()->register_callback(
 *                 'com.sergiosgc.zeromass.pluginInit', 
 *                 array($this, 'init')
 *             );
 *             \ZeroMass::getInstance()->register_callback(
 *                 'com.sergiosgc.zeromass.answerPage', 
 *                 array($this, 'hello')
 *             );
 *         }
 *         public function init() {
 *             SomeClass::someMethodThatMayThrowAnException();
 *         }
 *         public function hello($answered) {
 *             if ($answered) return $answered;
 *             if ($_SERVER['REQUEST_URI'] != '/') return $answered;
 *     ?>
 *     <!doctype html>
 *     <html>
 *      <head>
 *       <title>Hello World</title>
 *      </head>
 *      <body>
 *       Hello World
 *      </body>
 *     </html>
 *     <?php
 *             return true;
 *         }
 *     }
 *
 *     new HelloWorld();
 *
 * The reason for this is to allow plugins to hook into 
 * `com.sergiosgc.zeromass.pluginInit.exception` and handle thrown exceptions, 
 * either recovering or failing gracefully.
 *
 * ## Webserver configuration
 *
 * Webserver configuration is rather simple. The ZeroMass file list looks
 * like this:
 *
 *     private/
 *     public/plugins/
 *     public/plugins/com.sergiosgc.zeromass.php
 *
 * Move these to a proper place on your filesystem. For example, `/srv/www`.
 * You will now have:
 *
 *     /srv/www/private/
 *     /srv/www/public/plugins/
 *     /srv/www/public/plugins/com.sergiosgc.zeromass.php
 *
 * Have your webserver use `/srv/www` as its document root, directly answer any
 * requests for files in the filesystem, and route any requests for files
 * that do not exist to `/srv/www/public/plugins/com.sergiosgc.zeromass.php`
 * via PHP. 
 *
 * The virtualhost for nginx, using php-fpm, would be:
 *
 *     server {
 *         listen       :80;
 *         root   /srv/www;
 *     
 *         location / {
 *             try_files $uri $uri/index.php /zeromass/plugins/com.sergiosgc.zeromass.php?$args;
 *         }
 *     
 *         location ~ \.php$ {
 *             fastcgi_pass   fastcgi_backend;
 *             fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
 *             include        fastcgi_params;
 *         }
 *     }
 * 
 * ## Ramblings, FAQ and odd bits and pieces
 *
 * ### Why write ZeroMass?
 *
 * My itch. I scratched it. 
 *
 * It's 2012 and web development frameworks/CMSs 
 * (the lines blur) are a dime a dozen. I've written stuff or maintained code
 * in at least CodeIgniter, CakePHP, Joomla, Zend, eZPublish, Midgard and 
 * Drupal (damn, this list is long, meaning I'm old). I make my living around 
 * WordPress. All have highs and lows, and this list in particular has many
 * more highs than lows. However, all of them have one fault: they're 
 * monolithic.
 *
 * Now, don't bash me. Monolithic is sometimes good. It's predictable. The more
 * specific problem a software package solves, the more choices will have been 
 * done towards solving that particular goal. CMSs are highly monolithic (try 
 * changing the WordPress templating system). That's ok. However, it itches me
 * that, for example, authentication, i have exactly one choice:
 *
 *  - [Zend_Auth](http://framework.zend.com/manual/1.12/en/zend.auth.html) if using Zend
 *  - [User module](http://drupal.org/documentation/modules/user) on Drupal
 *  - [Authentication component](http://book.cakephp.org/2.0/en/core-libraries/components/authentication.html) on CakePHP
 *  - ...
 *
 * When I'm starting a new webapp, no choices have been done, and being 
 * presented with pre-selected choices is:
 *  
 *  - a) a nice to have shortcut if it is an acceptable choice
 *  - b) a nightmare of bending frameworks out of their way if the choice does 
 *       not fit
 *
 * Most of these packages sidestep the choice problem using plugin systems.
 * This solves 80% of the problems. For the rest of the cases, either 
 * the hook you need is missing, or there is some assumption in the basic 
 * design of the existing component that clashes unrecoverably with your needs.
 *
 * This is not enough motivation for ZeroMass. After all, all ZeroMass does 
 * is start with the hook system at its core. Given enough plugins and features,
 * extensibility will still be a problem. Hooks will still be missing and 
 * architectures will still be wrong for some problems.
 *
 * Then, some time ago, I read an article about the effect of GitHub on 
 * Open Source Software. Sorry, I can't find the link on my delicious, and 
 * can't find it on Google. I'll have to make a poor summary, with apologies
 * to the original author for the lack of referral: 
 *
 * GitHub, with its popularity and with its pull requests, has changed the 
 * landscape of OSS. Pre-github, good OSS came from close-knit groups with 
 * benevolent dictators (or dictator-boards) directing development. Examples 
 * abound: Linux, Apache httpd, OpenOffice, PEAR, Zend, Mozilla, etc.
 *
 * Post-github, OSS development became a lot more chaotic. Follow the 
 * developemnt of software that was born on github and take a look at pull
 * requests say, for example on [Meteor](https://github.com/meteor/meteor/pulls).
 * Lots and lots of small/medium improvements are done by people outside the
 * core developers. OSS suddenly became a lot less political and a lot more
 * inclusive. It is also a lot more fragmented, since the barrier to 
 * participation is so small, it is easy to produce __and contribute__ tiny
 * bits of code.
 *
 * Pre-github, should you wish to add to a project, you'd have 
 * [formal processes](http://pear.php.net/manual/en/newmaint.proposal.php). 
 * Post-github, you have pull requests. It's a deal maker. Unsurprisingly
 coders like to code, they don't like politics. I once wrote [XML_RPC2](http://pear.php.net/package/XML_RPC2/)
 * for PEAR. It took me a week to write and [six months](http://marc.info/?l=pear-dev&m=111581594219886&w=1) to get it accepted into
 * PEAR. It was the last PEAR package I wrote. I don't want to be in either
 * end of package approval processes like PEAR's, or code approval processes
 * like Horde's, or OpenOffice's.
 *
 * With ZeroMass distributed philosophy, I hope that, when you encounter
 * the situation where a plugin does not perform like you want, you can just
 * fork _the plugin_, fix it for your needs, and issue a pull request. Don't
 * get bothered with politics, and just code.
 *
 * ZeroMass is the foundation for a webapp framework in a post-github world.
 * I will probably build enough plugins for a complete application stack. I
 * don't want my plugins that sit above ZeroMass to be the sole plugins
 * that may sit above ZeroMass. If I write an authentication plugin for 
 * ZeroMass, it will be __an__ auth plugin, not __the__ auth plugin. Plus,
 * I don't get a say in development of plugins.
 *
 * Perhaps, the next time I write another webapp, I don't have to take the
 * choice of going with a huge framework or writing yet another _frakking_ 
 * login screen.
 *
 * Full circle in this text: ZeroMass is an unix-philosophy approach to web 
 * application development, performing a very limited set of features extremely
 * well.
 *
 * ### 200 lines of code, 500 lines of documentation?
 *
 * I secretly want to be a fiction writer. Rick Castle, Nikki Heat style.
 *
 * ### This is not new
 *
 * It is not new. The plugin/hook system is heavily inspired by the [WordPress 
 * Plugin API](http://codex.wordpress.org/Plugin_API). The ideas for software
 * decoupling have been around the Aspect Oriented Programming community for
 * eons. As far as I know, this is the first time someone starts a framework 
 * from the plugin system, but this may be as wrong as starting to build a 
 * house from the roof down.
 *
 * ### This is not Aspect Oriented Programming
 *
 * The hook system is __inspired__ by Aspect Oriented Development. It is not
 * AOP by any measure. AOP usually includes a lot of stuff ZeroMass does not 
 * provide: 
 *
 *  - Implicit Join Points, which require cooperation by the language compiler. 
 *    Here, all Join Points (hooks) must be explicitely declared, with the result
 *    that plugin developers will miss some. Let's hope plugins get forked and 
 *    corrected 
 *  - Syntactic sugar. Again, it requires either precompilation or cooperation
 *    by the language compiler. I'm not crazy enough to mess with Zend 
 *    internals. 'Been there, done that, ugly results.
 *  - Rich pointcut models. Proper AOP matches pointcuts at compile time.
 *  - Before, after and around advices. Before and after advices can be 
 *    simulated with explicit hooks. Around advices require the plugin firing 
 *    hook to expect the possibility for an around advice.
 *
 * All in all, this is how close you can get to real AOP in PHP without touching
 * Zend internals and without ugly hacks like a precompiler.
 *
 * ### What now?
 *
 * You've reached the end of the documentation. The final chapter. You know 
 * the butler did it, in the living room, with poison (a feminine murder 
 * weapon).
 *
 * I suggest you [install](https://github.com/sergiosgc/ZeroMass-Plugins) `com.sergiosgc.pluginManager`. It is a ... plugin manager
 * for ZeroMass (surprising!), with cool plugin documentation abilities and 
 * repository listing, where you can find more plugins.
 *
 * Or start writing your own plugins. Drop me a note if you publish them, I'd 
 * like to list known plugins somewhere and ease the search for ZeroMass plugins.
 *
 * @author Sérgio Carvalho <sergiosgc@gmail.com>
 * @copyright 2012, Sérgio Carvalho
 * @version 1.0
 */
?>
