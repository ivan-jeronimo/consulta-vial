<?php

namespace App\Services\Reto;

use App\Models\AjusteSitio;
use App\Models\Partida;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Imagen 1200×630 (formato de vista previa de Facebook y WhatsApp) con el
 * resultado de una partida, para invitar a otros a jugar. Se genera una vez.
 */
class TarjetaResultado
{
    private const ANCHO = 1200;

    private const ALTO = 630;

    public function ruta(Partida $partida): string
    {
        $ruta = "sitio/compartir/{$partida->id}.png";

        if (! Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->put($ruta, $this->dibujar($partida));
        }

        return $ruta;
    }

    /** "Ivan Jeronimo" → "Ivan J.": el enlace puede circular fuera de la comunidad. */
    public static function nombreCorto(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre));

        return trim($partes[0].(isset($partes[1]) ? ' '.mb_substr($partes[1], 0, 1).'.' : ''));
    }

    private function dibujar(Partida $partida): string
    {
        $img = imagecreatetruecolor(self::ANCHO, self::ALTO);
        imagesavealpha($img, true);

        $asfalto = imagecolorallocate($img, 0x18, 0x18, 0x1B);
        $amarillo = imagecolorallocate($img, 0xFA, 0xCC, 0x15);
        $blanco = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
        $gris = imagecolorallocate($img, 0xA8, 0xA2, 0x9E);

        imagefill($img, 0, 0, $asfalto);
        $this->portada($img);

        // Línea discontinua de carretera abajo.
        for ($x = 0; $x < self::ANCHO; $x += 56) {
            imagefilledrectangle($img, $x, self::ALTO - 14, $x + 33, self::ALTO - 1, $amarillo);
        }

        $x = 80;
        $titulo = $this->logo($img, $x, 64, 112) ? $x + 140 : $x;
        $this->texto($img, 30, $titulo, 112, $amarillo, 'EL RETO DEL ASFALTO', negrita: true);
        $this->texto($img, 22, $titulo, 152, $gris, 'Simulador del examen de manejo · Oaxaca');

        $nombre = self::nombreCorto($partida->jugador->nombre);
        $this->texto($img, $this->ajustar(48, $nombre, self::ANCHO - 2 * $x, true), $x, 270, $blanco, $nombre, negrita: true);

        $puntos = number_format($partida->puntos);
        $caja = $this->texto($img, 150, $x - 6, 450, $amarillo, $puntos, negrita: true);
        $this->texto($img, 44, $caja[2] + 22, 450, $blanco, 'puntos', negrita: true);

        $total = $partida->total();
        $this->texto($img, 34, $x, 520, $blanco, "{$partida->aciertos} de {$total} correctas");
        $this->texto($img, 30, $x, 580, $amarillo, '¿Me superas? Juega gratis en '.$this->dominio(), negrita: true);

        ob_start();
        imagepng($img, null, 6);

        return (string) ob_get_clean();
    }

    /** Portada de la página, oscurecida, como fondo. */
    private function portada(GdImage $img): void
    {
        $fondo = $this->cargar(AjusteSitio::valor('portada'));
        if (! $fondo) {
            return;
        }

        // Recorte tipo "cover": llena 1200×630 sin deformar.
        $escala = max(self::ANCHO / imagesx($fondo), self::ALTO / imagesy($fondo));
        $w = (int) ceil(self::ANCHO / $escala);
        $h = (int) ceil(self::ALTO / $escala);
        imagecopyresampled($img, $fondo, 0, 0, (int) ((imagesx($fondo) - $w) / 2), (int) ((imagesy($fondo) - $h) / 2), self::ANCHO, self::ALTO, $w, $h);

        // Velo oscuro para que el texto se lea.
        imagefilledrectangle($img, 0, 0, self::ANCHO, self::ALTO, imagecolorallocatealpha($img, 0x18, 0x18, 0x1B, 22));
    }

    /** Logo en círculo con borde amarillo. */
    private function logo(GdImage $img, int $x, int $y, int $diametro): bool
    {
        $logo = $this->cargar(AjusteSitio::valor('logo'));
        if (! $logo) {
            return false;
        }

        $borde = 6;
        imagefilledellipse($img, $x + $diametro / 2, $y + $diametro / 2, $diametro + 2 * $borde, $diametro + 2 * $borde, imagecolorallocate($img, 0xFA, 0xCC, 0x15));

        $lado = min(imagesx($logo), imagesy($logo));
        $r = $diametro / 2;
        for ($i = 0; $i < $diametro; $i++) {
            for ($j = 0; $j < $diametro; $j++) {
                if (($i - $r + 0.5) ** 2 + ($j - $r + 0.5) ** 2 <= $r ** 2) {
                    $color = imagecolorat($logo, (int) ($i * $lado / $diametro), (int) ($j * $lado / $diametro));
                    imagesetpixel($img, $x + $i, $y + $j, $color);
                }
            }
        }

        return true;
    }

    private function cargar(?string $ruta): ?GdImage
    {
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        $imagen = @imagecreatefromstring(Storage::disk('public')->get($ruta));

        return $imagen ?: null;
    }

    /** @return array<int, int> caja del texto (imagettftext) */
    private function texto(GdImage $img, float $tamano, int $x, int $y, int $color, string $texto, bool $negrita = false): array
    {
        return imagettftext($img, $tamano, 0, $x, $y, $color, $this->fuente($negrita), $texto);
    }

    /** Achica el tamaño hasta que el texto cabe en el ancho. */
    private function ajustar(float $tamano, string $texto, int $ancho, bool $negrita): float
    {
        while ($tamano > 20) {
            $caja = imagettfbbox($tamano, 0, $this->fuente($negrita), $texto);
            if ($caja[2] - $caja[0] <= $ancho) {
                break;
            }
            $tamano -= 2;
        }

        return $tamano;
    }

    /** Inter estática (resources/fonts): GD no sabe elegir el peso de una fuente variable. */
    private function fuente(bool $negrita): string
    {
        $ruta = resource_path('fonts/'.($negrita ? 'Inter-ExtraBold.ttf' : 'Inter-Regular.ttf'));

        return is_file($ruta) ? $ruta : throw new RuntimeException("Falta la fuente {$ruta}.");
    }

    private function dominio(): string
    {
        $url = parse_url((string) config('services.facebook.sitio_url'));

        return ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');
    }
}
