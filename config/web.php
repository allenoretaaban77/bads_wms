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
                'POST api/employee/login' => 'employee/login',
                'POST api/employee/register' => 'employee/register',
                'GET api/employee/profile' => 'employee/profile',
                'GET api/employee/list' => 'employee/list',
                'PUT api/employee/update' => 'employee/update',
                'DELETE api/employee/delete' => 'employee/delete',
                'GET api/inventory/list' => 'inventory/list',
                'POST api/inventory/create' => 'inventory/create',
                'POST api/inventory/checksku' => 'inventory/checksku',
                // 'GET api/inventory/view' => 'inventory/view',
                // 'PUT api/inventory/update' => 'inventory/update',
                // 'DELETE api/inventory/delete' => 'inventory/delete',
            ],
        ],
    ],
    'as corsFilter' => [
        'class' => Cors::class,
        'cors' => [
            'Origin' => ['http://localhost:3000'], // allow React/Vue dev server
            'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
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
