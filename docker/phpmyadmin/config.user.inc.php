<?php

// Auth automático para entorno local: no muestra pantalla de login.
$cfg['Servers'][$i]['auth_type'] = 'config';
$cfg['Servers'][$i]['user'] = getenv('PMA_USER');
$cfg['Servers'][$i]['password'] = getenv('PMA_PASSWORD');
$cfg['Servers'][$i]['AllowNoPassword'] = true;
