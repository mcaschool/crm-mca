<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;

/**
 * Filtro barato SIN IA (Paso 1 del enrutamiento de Celia): por palabras clave,
 * detecta si la pregunta cae en un tema que el ARBOL ya resuelve. Si hay
 * coincidencia clara, Celia ofrece los botones del nodo correspondiente y NO
 * gasta tokens. Bilingue (es/en).
 */
class TopicRouter
{
    /**
     * key de nodo del arbol => palabras clave (normalizadas, sin acentos).
     *
     * @var array<string, array<int,string>>
     */
    private const TOPICS = [
        // OJO: 'credential' se excluye a proposito (colisiona con "microcredential").
        'NODE_CERTIFICACION' => ['certific', 'certificado', 'diploma', 'titulacion', 'titulo', 'verificacion', 'linkedin'],
        'NODE_METODOLOGIA' => ['metodolog', 'duracion', 'cuanto dura', 'semanas', 'online', 'ritmo', 'plataforma', 'evaluacion', 'examen', 'como se aprende', 'methodology', 'duration', 'how long', 'weeks', 'self-paced'],
        'NODE_INSCRIPCION' => ['inscrip', 'requisito', 'matricula', 'cupon', 'descuento', 'pago', 'como me inscribo', 'enroll', 'requirement', 'coupon', 'discount', 'sign up'],
        'NODE_QUE_ES' => ['que es una microcred', 'que es microcred', 'diferencia con un curso', 'en que consiste', 'what is a microcred', 'difference from a course'],
        'NODE_PROYECCION' => ['proyeccion', 'sirve para el trabajo', 'empleabilidad', 'mi cv', 'curriculum', 'career', 'employab', 'resume'],
    ];

    /**
     * Marcadores de una pregunta por un dato ESPECIFICO que el arbol no responde a
     * nivel de menu (horas academicas, creditos, precio, fecha). Aunque la pregunta
     * contenga una palabra de tema (p. ej. "diploma"), NO se enruta a botones: se
     * manda a IA para que responda con la base de conocimiento o derive con
     * honestidad. Evita los "callejones sin salida" de menus que no contienen la
     * respuesta. Normalizados (sin acentos, minusculas).
     *
     * @var array<int,string>
     */
    private const DETAIL_MARKERS = [
        'horas', 'hora academica', 'horas academicas', 'credito', 'creditos',
        'precio', 'cuanto cuesta', 'cuanto vale', 'cuanto sale',
        'que fecha', 'fecha de inicio', 'proxima fecha', 'cuando empieza', 'cuando inicia', 'cuando comienza',
        'hours', 'academic hours', 'credit', 'credits', 'price', 'how much', 'what date', 'start date', 'when does it start',
    ];

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
     * Devuelve la key del nodo del arbol si la pregunta encaja claramente; null si no.
     */
    public function match(string $message): ?string
    {
        $normalized = Str::of($message)->lower()->ascii()->toString();

        // Regla clave: si preguntan por un dato especifico que el arbol no responde
        // (horas/creditos/precio/fecha), NO se enruta a botones -> va a IA.
        foreach (self::DETAIL_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return null;
            }
        }

        foreach (self::TOPICS as $nodeKey => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($normalized, $kw)) {
                    return $nodeKey;
                }
            }
        }

        return null;
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
