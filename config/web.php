<?php

use yii\filters\Cors;
use yii\filters\ContentNegotiator;
use yii\web\Response;

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => 'mUYskUS3ho4EfuT7lsgKXrLgpkKY9_tb',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ]
        ],
        'response' => [
            'format' => Response::FORMAT_JSON,
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            // send all mails to a file by default.
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                // API routes for Employee
                'POST,OPTIONS api/employee/login' => 'employee/login',
                'POST,OPTIONS api/employee/register' => 'employee/register',
                'GET,OPTIONS api/employee/profile' => 'employee/profile',
                'GET,OPTIONS api/employee/list' => 'employee/list',
                'PUT,OPTIONS api/employee/update' => 'employee/update',
                'DELETE,OPTIONS api/employee/delete' => 'employee/delete',
                
                // API routes for Inventory 👇 CHANGED THESE TO INCLUDE OPTIONS
                'GET,OPTIONS api/inventory/list' => 'inventory/list',
                'GET,OPTIONS api/inventory/listsearch' => 'inventory/listsearch',
                'POST,OPTIONS api/inventory/checksku' => 'inventory/checksku',
                'POST,OPTIONS api/inventory/create' => 'inventory/create',
                'PUT,OPTIONS api/inventory/update' => 'inventory/update',
                'PATCH,OPTIONS api/inventory/update' => 'inventory/update',
                'DELETE,OPTIONS api/inventory/delete' => 'inventory/delete',
                
                // API routes for Replenishment
                'GET,OPTIONS api/replenishment/list' => 'replenishment/list',
                'GET,OPTIONS api/replenishment/view' => 'replenishment/view',
                'GET,OPTIONS api/replenishment/viewavailablestocks' => 'replenishment/viewavailablestocks',
                'GET,OPTIONS api/replenishment/stockintrnxs' => 'replenishment/stockintrnxs',
                'POST,OPTIONS api/replenishment/create' => 'replenishment/create',
                'PUT,OPTIONS api/replenishment/update' => 'replenishment/update',
                'PATCH,OPTIONS api/replenishment/update' => 'replenishment/update',
                'PUT,OPTIONS api/replenishment/approve' => 'replenishment/approve',
                'PATCH,OPTIONS api/replenishment/approve' => 'replenishment/approve',
                'DELETE,OPTIONS api/replenishment/delete' => 'replenishment/delete',
                'GET,OPTIONS api/replenishment/generatetrnxno' => 'replenishment/generatetrnxno',

                // API routes for Sales
                'GET,OPTIONS api/sales/list' => 'sales/list',
                'GET,OPTIONS api/sales/view' => 'sales/view',
                'POST,OPTIONS api/sales/create' => 'sales/create',
                'PUT,OPTIONS api/sales/update' => 'sales/update',
                'PATCH,OPTIONS api/sales/update' => 'sales/update',
                'PUT,OPTIONS api/sales/approve' => 'sales/approve',
                'PATCH,OPTIONS api/sales/approve' => 'sales/approve',
                'DELETE,OPTIONS api/sales/delete' => 'sales/delete',
                'DELETE,OPTIONS api/sales/void' => 'sales/void',
                'GET,OPTIONS api/sales/generatetrnxno' => 'sales/generatetrnxno',
            ],
        ],
        'formatter' => [
            'timeZone' => 'Asia/Manila',
            'defaultTimeZone' => 'Asia/Manila',
        ],
    ],
    'as corsFilter' => [
        'class' => Cors::class,
        'cors' => [
            'Origin' => ['http://localhost:3000'],
            'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            'Access-Control-Request-Headers' => ['*'],
            'Access-Control-Allow-Credentials' => true,
        ],
    ],
    'as contentNegotiator' => [
        'class' => ContentNegotiator::class,
        'formats' => [
            'application/json' => Response::FORMAT_JSON,
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return $config;
