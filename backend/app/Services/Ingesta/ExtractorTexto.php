<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class ExtractorTexto
{
    public const MIMES_SOPORTADOS = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/markdown',
    ];

    public function __construct(private DescriptorImagenes $imagenes) {}

    public function extraer(string $rutaAbsoluta, string $mime): string
    {
        $texto = match ($mime) {
            'application/pdf' => $this->desdePdf($rutaAbsoluta),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->desdeDocx($rutaAbsoluta),
            'text/plain', 'text/markdown' => file_get_contents($rutaAbsoluta),
            default => throw new RuntimeException("Tipo de archivo no soportado: {$mime}"),
        };

        if (mb_strlen(trim($texto)) < 50) {
            throw new RuntimeException('El documento no contiene texto extraíble (¿PDF escaneado? requiere OCR).');
        }

        return $texto;
    }

    private function desdePdf(string $ruta): string
    {
        // pdftotext (Poppler) respeta los espacios entre palabras mucho mejor que
        // smalot/pdfparser, que en algunos PDF oficiales produce "ARTÍCULO52.Los".
        $pdftotext = Process::run(['pdftotext', '-enc', 'UTF-8', $ruta, '-']);

        if ($pdftotext->successful() && trim($pdftotext->output()) !== '') {
            $paginas = explode("\f", rtrim($pdftotext->output(), "\f"));

            // Lo que solo existe como imagen (nombres de señales, diagramas) se
            // agrega como texto al final de su página.
            foreach ($this->imagenes->describir($ruta, count($paginas)) as $pagina => $descripcion) {
                // La nota distingue lo transcrito por IA del texto literal del documento.
                $paginas[$pagina - 1] = ($paginas[$pagina - 1] ?? '')
                    ."\n\nIlustraciones de la página {$pagina} (nombres transcritos y descripción visual generada automáticamente a partir de las imágenes):\n"
                    .$descripcion;
            }

            // Mismo separador de páginas que la otra ruta, para el normalizador.
            return implode("\n\f\n", $paginas);
        }

        $documento = (new PdfParser)->parseFile($ruta);

        // Página por página con salto de forma explícito, para que el
        // normalizador pueda detectar encabezados/pies repetidos.
        return collect($documento->getPages())
            ->map(fn ($pagina) => $pagina->getText())
            ->implode("\n\f\n");
    }

    private function desdeDocx(string $ruta): string
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo DOCX.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('DOCX inválido: falta word/document.xml.');
        }

        // Cada párrafo <w:p> es una línea; tabs y saltos manuales se respetan.
        $xml = preg_replace(['/<w:tab\/>/', '/<w:br[^>]*\/>/', '/<\/w:p>/'], ["\t", "\n", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
