<?php declare(strict_types=1);

namespace Monarc\Sign;

use Monarc\FrontOffice\Entity\UserRole;

return [
    'doctrine' => [
        'driver' => [
            'monarc_sign_driver' => [
                'class' => \Doctrine\ORM\Mapping\Driver\AnnotationDriver::class,
                'cache' => 'array',
                'paths' => [__DIR__ . '/../src/Entity'],
            ],
            'orm_default' => [
                'drivers' => [
                    'Monarc\Sign\Entity' => 'monarc_sign_driver',
                ],
            ],
            'orm_cli' => [
                'drivers' => [
                    'Monarc\Sign\Entity' => 'monarc_sign_driver',
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            \Monarc\Sign\Service\Signature\SignatureRequestService::class => \Monarc\Sign\Service\Signature\SignatureRequestServiceFactory::class,
            \Monarc\Sign\Service\Signature\SignatureProcessService::class => \Monarc\Sign\Service\Signature\SignatureProcessServiceFactory::class,
            \Monarc\Sign\Service\Signature\TrustedXSignatureClient::class => \Monarc\Sign\Service\Signature\TrustedXSignatureClientFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\PostInitiateSignInputValidator::class => \Monarc\Sign\Validator\InputValidator\Signature\SignatureInputValidatorFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\PostRejectSignatureInputValidator::class => \Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\PostSignatureRequestInputValidator::class => \Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\PostSignatureCallbackInputValidator::class => \Monarc\Sign\Validator\InputValidator\Signature\SignatureInputValidatorFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\PostUploadSignedDocumentInputValidator::class => \Monarc\Sign\Validator\InputValidator\Signature\SignatureInputValidatorFactory::class,
            \Monarc\Sign\Validator\InputValidator\Signature\GetReauthUrlInputValidator::class => \Monarc\Sign\Validator\InputValidator\Signature\SignatureInputValidatorFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            \Monarc\Sign\Controller\Signature\ApiSignatureRequestsController::class => \Monarc\Sign\Controller\Signature\ApiSignatureRequestsControllerFactory::class,
            \Monarc\Sign\Controller\Signature\ApiSignController::class => \Monarc\Sign\Controller\Signature\ApiSignControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'monarc_api_signature_requests' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/anrs/:anrId/signature-requests[/:id]',
                    'constraints' => [
                        'anrId' => '[0-9]+',
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignatureRequestsController::class,
                    ],
                ],
            ],
            'monarc_api_signature_pdf' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/pdf',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'pdf',
                    ],
                ],
            ],
            'monarc_api_sign_process' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/sign',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'sign',
                    ],
                ],
            ],
            'monarc_api_sign_check_token' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/check-token',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'checkToken',
                    ],
                ],
            ],
            'monarc_api_sign_reject' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/reject',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'reject',
                    ],
                ],
            ],
            'monarc_api_sign_upload_signed' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/upload-signed',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'uploadSigned',
                    ],
                ],
            ],
            'monarc_api_signature_callback' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/callback',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'callback',
                    ],
                ],
            ],
            'monarc_api_signature_reauth_url' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/reauth-url',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'reauthUrl',
                    ],
                ],
            ],
            'monarc_api_signature_refresh_tx_token' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/signature-requests/:id/refresh-tx-token',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Sign\Controller\Signature\ApiSignController::class,
                        'action' => 'refreshTxToken',
                    ],
                ],
            ],
        ],
    ],
    'permissions' => [
        'monarc_api_signature_callback',
        'monarc_api_signature_pdf',
        'monarc_api_sign_upload_signed',
        'monarc_api_signature_refresh_tx_token',
    ],
    'roles' => [
        UserRole::SUPER_ADMIN_FO => [
            'monarc_api_signature_requests',
            'monarc_api_signature_pdf',
            'monarc_api_sign_process',
            'monarc_api_sign_check_token',
            'monarc_api_sign_reject',
            'monarc_api_sign_upload_signed',
            'monarc_api_signature_reauth_url',
            'monarc_api_signature_refresh_tx_token',
        ],
        UserRole::USER_FO => [
            'monarc_api_signature_requests',
            'monarc_api_signature_pdf',
            'monarc_api_sign_process',
            'monarc_api_sign_check_token',
            'monarc_api_sign_reject',
            'monarc_api_sign_upload_signed',
            'monarc_api_signature_reauth_url',
            'monarc_api_signature_refresh_tx_token',
        ],
    ],
];
