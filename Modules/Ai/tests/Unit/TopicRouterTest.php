<?php

declare(strict_types=1);

use Modules\Ai\Services\TopicRouter;

it('mapea preguntas de temas del arbol a su nodo, sin IA', function () {
    $router = new TopicRouter;

    expect($router->match('¿El certificado tiene verificacion?'))->toBe('NODE_CERTIFICACION');
    expect($router->match('¿Cuanto dura y es online?'))->toBe('NODE_METODOLOGIA');
    expect($router->match('¿Como me inscribo y hay cupones?'))->toBe('NODE_INSCRIPCION');
    expect($router->match('what is a microcredential?'))->toBe('NODE_QUE_ES');
});

it('devuelve null cuando la pregunta es abierta o ambigua', function () {
    $router = new TopicRouter;

    expect($router->match('¿Cual me recomiendas para mi carrera en ventas?'))->toBeNull();
    expect($router->match('Hola, buenos dias'))->toBeNull();
});

it('NO enruta a botones una pregunta por un dato especifico que el arbol no responde', function () {
    $router = new TopicRouter;

    // Contiene "diploma" (tema certificacion) pero pregunta por HORAS -> va a IA, no a botones.
    expect($router->match('¿por cuantas horas sale el diploma?'))->toBeNull();
    // Creditos y precio tampoco son datos de menu.
    expect($router->match('¿cuantos creditos son?'))->toBeNull();
    expect($router->match('¿cual es el precio del certificado?'))->toBeNull();
    // Becas no es un nodo del arbol -> IA (con derivacion a inscripciones).
    expect($router->match('¿manejan becas?'))->toBeNull();
});

it('si enruta un tema legitimo que el arbol SI cubre', function () {
    $router = new TopicRouter;

    expect($router->match('¿que certificado dan?'))->toBe('NODE_CERTIFICACION');
    expect($router->match('¿como es la metodologia?'))->toBe('NODE_METODOLOGIA');
});

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
