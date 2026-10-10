<?php

return [

    'admin_token' => env('ADMIN_API_TOKEN'),

    // Clientes de la API de fuentes (solo lectura de documentos y su texto), separados por coma.
    // Uno por cliente para poder revocarlos por separado (p. ej. el sitio Andantes del Asfalto).
    'fuentes_tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('FUENTES_API_TOKENS', ''))))),

    'ingesta' => [
        'disk' => env('REGLAMENTOS_DISK', 'local'),
        'directorio' => 'reglamentos',
        'cola' => 'ingesta',

        // Un artículo más largo que esto se subdivide por fracciones.
        'max_caracteres_chunk' => 6000,

        // Describir con IA (visión) las páginas de PDF con ilustraciones, p. ej.
        // señales cuyo nombre solo está dibujado. Requiere LLM_PROVIDER=gemini.
        'describir_imagenes' => (bool) env('INGESTA_DESCRIBIR_IMAGENES', true),
    ],

    'busqueda' => [
        // Fragmentos que se entregan al LLM como contexto.
        'resultados' => 8,
        // Candidatos que aporta cada método (vectorial y texto) antes de fusionar.
        'candidatos' => 40,
    ],

    'metricas' => [
        // Similitud coseno mínima para que dos preguntas cuenten como el mismo
        // tema. Calibrado con gemini-embedding-2: misma intención ≥ 0.80,
        // temas distintos ≤ 0.77.
        'similitud_tema' => (float) env('METRICAS_SIMILITUD_TEMA', 0.79),
    ],

    'llm' => [
        // anthropic | openai | gemini | none (solo devuelve los artículos encontrados)
        'provider' => env('LLM_PROVIDER', 'anthropic'),
        'model' => env('LLM_MODEL'),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 4096),
        // Nivel de razonamiento para modelos Gemini 3 (low | high); vacío = el del modelo.
        'razonamiento' => env('LLM_THINKING', 'low'),

        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => 'claude-sonnet-5-5',
        ],
    ],

    'embeddings' => [
        'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('EMBEDDINGS_MODEL', 'text-embedding-3-small'),

        // Fijo en la migración de reglamentos_articulos: vector(N).
        'dimensions' => (int) env('EMBEDDINGS_DIMENSIONS', 1536),

        'batch_size' => (int) env('EMBEDDINGS_BATCH_SIZE', 96),

        // Tope de textos enviados por minuto (0 = sin tope). Nivel gratuito de
        // Gemini: 100; se deja margen para las consultas del bot.
        'textos_por_minuto' => (int) env('EMBEDDINGS_TEXTS_PER_MINUTE', 0),

        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],
    ],

    // Límites del chat público: cada pregunta cuesta una llamada al LLM y otra
    // de embeddings. Se reinician a medianoche. 0 = sin límite.
    'limites' => [
        // Por navegador (id anónimo del visitante; sin id, por conexión).
        'por_persona_al_dia' => (int) env('CONSULTA_LIMITE_PERSONA', 5),
        // Por conexión: respaldo si alguien borra su id. Más alto porque
        // varias personas pueden compartir IP (datos móviles, una oficina).
        'por_ip_al_dia' => (int) env('CONSULTA_LIMITE_IP', 30),
        // Tope de todo el servicio, para no pasar de la cuota del LLM.
        'total_al_dia' => (int) env('CONSULTA_LIMITE_TOTAL', 500),
    ],

];
