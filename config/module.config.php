<?php declare(strict_types=1);

namespace Monarc\Extension;

use Monarc\FrontOffice\Entity\UserRole;

return [
    'doctrine' => [
        'driver' => [
            'monarc_extension_driver' => [
                'class' => \Doctrine\ORM\Mapping\Driver\AnnotationDriver::class,
                'cache' => 'array',
                'paths' => [__DIR__ . '/../src/Entity'],
            ],
            'orm_default' => [
                'drivers' => [
                    'Monarc\Extension\Entity' => 'monarc_extension_driver',
                ],
            ],
            'orm_cli' => [
                'drivers' => [
                    'Monarc\Extension\Entity' => 'monarc_extension_driver',
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            \Monarc\Extension\Provider\GenericOAuth2IdentityProvider::class => \Monarc\Extension\Provider\IdentityProviderFactory::class,
            \Monarc\Extension\Service\IdentityManagementService::class => \Monarc\Extension\Service\IdentityManagementServiceFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            \Monarc\Extension\Controller\SsoAuthenticationController::class => \Monarc\Extension\Controller\SsoAuthenticationControllerFactory::class,
            \Monarc\Extension\Controller\ApiUsersIdentityController::class => \Monarc\Extension\Controller\ApiUsersIdentityControllerFactory::class,
            \Monarc\Extension\Controller\ApiIdentityProvidersController::class => \Monarc\Extension\Controller\ApiIdentityProvidersControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            // Routes publiques d'authentification SSO
            'auth_sso_redirect' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/auth/sso/redirect',
                    'defaults' => [
                        'controller' => \Monarc\Extension\Controller\SsoAuthenticationController::class,
                        'action' => 'redirect',
                    ],
                ],
            ],
            'auth_sso_callback' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/auth/sso/callback',
                    'defaults' => [
                        'controller' => \Monarc\Extension\Controller\SsoAuthenticationController::class,
                        'action' => 'callback',
                    ],
                ],
            ],
            // Routes API REST d'administration de l'identité numérique
            'monarc_api_admin_user_identity' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/users/:userId/identity[/:id]',
                    'constraints' => [
                        'userId' => '[0-9]+',
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Extension\Controller\ApiUsersIdentityController::class,
                    ],
                ],
            ],
            'monarc_api_admin_identity_providers' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/identity-providers[/:id]',
                    'constraints' => [
                        'id' => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => \Monarc\Extension\Controller\ApiIdentityProvidersController::class,
                    ],
                ],
            ],
        ],
    ],
    'permissions' => [
        'auth_sso_redirect',
        'auth_sso_callback',
        'monarc_api_admin_identity_providers',
    ],
    'roles' => [
        UserRole::SUPER_ADMIN_FO => [
            'monarc_api_admin_user_identity',
            'monarc_api_admin_identity_providers',
        ],
    ],
];