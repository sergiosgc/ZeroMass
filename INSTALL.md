Installation
============

This repository is a barebones ZeroMass application. You can clone it to start your own app.

## Step 1. Clone the repository

    cd /srv
    sudo mkdir myapp
    sudo chown $(whoami) myapp
    cd myapp
    git clone git@github.com:sergiosgc/ZeroMass.git .

Naturally, replace `myapp` with a meaningful name for your application.

## Step 2. Setup a host name for your app

Edit `/etc/hosts` and add this line:

    127.0.0.1    myapp.dev

Naturally, replace `myapp` with a meaningful name for your application.

## Step 3. Configure your webserver

The webserver should serve any file that exists on the public directory of the app, it should interpret PHP files, and whenever a file does not exist, it should serve `/zeromass/com.sergiosgc.zeromass.php`. 

For nginx + php-fpm, add this virtualhost:

    server {
      server_name myapp.dev;
    
      root /srv/myapp/public;
      index index.php;
    
      location / {
        try_files $uri /zeromass/com.sergiosgc.zeromass.php?$args;
      }
    
      location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
      }
    }
   
If you are on Ubuntu or Debian or any Debian based distro, add the contents above to a file named `/etc/nginx/sites-enabled/myapp.conf`.

It goes without saying: replace `myapp` with a meaningful name for your application.

## Step 4. Check that everything is ok

Point your browser to http://myapp.dev/. You should get a Hello World page, with instructions on how to start developing.
