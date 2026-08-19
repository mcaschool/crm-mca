<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;

/**
 * Utilidades SIN IA para el modo Celia. Detecta consultas CORPORATIVAS (InCompany)
 * y expone el mapa de temas que el arbol cubre, como contexto para el modelo. Ya NO
 * enruta preguntas a botones por palabra clave: en modo Celia toda consulta la
 * responde la IA con el conocimiento (el menu es ultimo recurso). Bilingue (es/en).
 */
class TopicRouter
{
    /**
     * Tema CORPORATIVO / InCompany: formacion para empresas y equipos. Cuando se
     * dispara, Celia NO cae en el filtro de botones; conversa y encamina al canal
     * corporativo (correo + formulario). Tiene precedencia sobre match() para que
     * frases como "descuento por volumen" no acaben en el menu de inscripcion.
     * Normalizados (sin acentos, minusculas). Multi-palabra donde hace falta para
     * no forzar a corporativo mensajes ambiguos.
     *
     * @var array<int,string>
     */
    private const CORPORATE_MARKERS = [
        'mi empresa', 'para mi empresa', 'una empresa', 'la empresa', 'de la empresa',
        'mi compania', 'para mi compania', 'mi equipo', 'para mi equipo', 'a mi equipo',
        'mi personal', 'nuestro personal', 'capacitar a mi personal', 'formacion para mi personal',
        'plan corporativo', 'corporativo', 'corporativa', 'in company', 'incompany',
        'descuento por volumen', 'varios empleados', 'a mis empleados', 'para empleados', 'nuestros empleados',
        'paquete para', 'capacitar a mi equipo', 'formar a mi equipo',
        'my company', 'my team', 'for my team', 'for my company', 'corporate', 'in-company',
        'bulk', 'volume discount', 'our employees', 'several employees', 'train my staff', 'my staff',
    ];

    /**
     * ¿El mensaje es una consulta de formacion corporativa/InCompany?
     */
    public function isCorporate(string $message): bool
    {
        $normalized = Str::of($message)->lower()->ascii()->toString();

        foreach (self::CORPORATE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mapa de temas del arbol para el contexto del modelo (Paso 2): que cubre el
     * arbol, para que Celia sepa cuando ofrecer botones en vez de conversar.
     */
    public function topicMap(): string
    {
        return 'Temas que el arbol de botones ya cubre (ofrece esos botones si la pregunta encaja): '.
            'que es una microcredencial; metodologia y experiencia de estudio (duracion, online, ritmo, plataforma, evaluacion); '.
            'certificacion y titulacion (diploma, certificado, verificacion, LinkedIn); inscripcion y requisitos (proceso, cupones, pago); '.
            'proyeccion profesional. Para orientar que programa elegir, existe un emparejador de 5 preguntas (action start_matcher).';
    }
}
