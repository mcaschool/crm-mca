<?php

declare(strict_types=1);

use Modules\Ai\Services\TopicRouter;

it('detecta consultas corporativas/InCompany', function () {
    $router = new TopicRouter;

    expect($router->isCorporate('quiero un paquete para mi empresa'))->toBeTrue();
    expect($router->isCorporate('necesito capacitar a mi equipo'))->toBeTrue();
    expect($router->isCorporate('¿hay descuento por volumen?'))->toBeTrue();
    expect($router->isCorporate('training for my team'))->toBeTrue();
    // No corporativo: consulta personal normal.
    expect($router->isCorporate('¿que es una microcredencial?'))->toBeFalse();
    expect($router->isCorporate('quiero mejorar mi CV'))->toBeFalse();
});

it('el mapa de temas describe lo que el arbol cubre, para el contexto del modelo', function () {
    $map = (new TopicRouter)->topicMap();

    expect($map)->toContain('microcredencial');
    expect($map)->toContain('start_matcher');
});
