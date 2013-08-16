<?php
namespace com\sergiosgc;
class Hello {
    public function __construct() {
        zm_register('com.sergiosgc.zeromass.answerPage', array($this, 'hello'));
    }
    public function hello($handled) {
?>
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="utf-8" />
  <title>ZeroMass is working</title>
 </head>
 <body>
  <h1>ZeroMass Hello World</h1>
  <p>Your ZeroMass installation is working. You should now reset the git project and remove the HelloWorld plugin to start developing.</p>
  <p>On your application directory (the one where you cloned the git repository), execute these commands:</p>
  <code><pre>
rm -Rf .git
git init .
rm public/zeromass/com.sergiosgc.hello.php
  </pre></code>
  <p>Do note that it is the hello world plugin that is providing this page. Once you remove it, you'll need to start serving your own pages. On the other hand, if you don't remove the hello world plugin, it will answer <b>all</b> pages so you can't really develop anything :-)</p>
  <p>This bare project has debug hooks on request turned on. You can add a debugHooks to the request and check how is the page generated. <a href="?debugHooks">Try it</a>. To disable this behaviour, delete the file <code>private/debugHooksOnRequest</code>.</p>
  <p>You can find plugins to use with ZeroMass <a href="https://github.com/sergiosgc/ZeroMass-Plugins">here</a> and you can see the plugin documentation using the <a href="https://github.com/sergiosgc/ZeroMass-Doc">ZeroMass-Doc application</a>. 
 </body>
</html>

<?php
        return true;
    }
}

new Hello();
?>
