<?php

namespace App\Tests\Api;

use App\Tests\ApiTestBase;
use Symfony\Component\ExpressionLanguage\Parser;

/**
 * Expressions de sécurité des ressources API Platform (security, securityPostDenormalize) :
 * toutes doivent rester analysables par l'ExpressionLanguage de l'application après une mise à
 * jour de symfony/expression-language (une expression invalide ferait échouer l'opération en 500
 * ou, pire, ne plus protéger la ressource). Leur effet est vérifié par OwnershipTest et DataLeakTest.
 */
class SecurityExpressionsTest extends ApiTestBase
{
    public function testEverySecurityExpressionIsValid(): void
    {
        $container = static::getContainer();
        $names = $container->get('api_platform.metadata.resource.name_collection_factory');
        $metadata = $container->get('api_platform.metadata.resource.metadata_collection_factory');
        $language = $container->get('security.expression_language');

        $expressions = [];
        foreach ($names->create() as $class) {
            foreach ($metadata->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    foreach ([$operation->getSecurity(), $operation->getSecurityPostDenormalize()] as $expression) {
                        if ($expression !== null) {
                            $expressions["$name : $expression"] = $expression;
                        }
                    }
                }
            }
        }

        // 4 expressions « security » et 19 « securityPostDenormalize » déclarées dans src/Entity.
        self::assertGreaterThanOrEqual(23, count($expressions));
        foreach ($expressions as $label => $expression) {
            // Variables fournies à l'exécution (object, user…) ; fonctions (is_granted…) vérifiées.
            $language->lint($expression, [], Parser::IGNORE_UNKNOWN_VARIABLES);
            self::assertNotEmpty($label);
        }
    }
}
