<?php

namespace App\Services;

use App\Models\TvChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TvLogoService
{
    protected string $storageDisk = 'public';
    protected string $logoDirectory = 'logos/channels';

    /**
     * Diccionario curado con URLs permanentes de Wikimedia Commons (Special:FilePath) y CDN oficiales.
     */
    protected array $curatedLogos = [
        // Honduras y Centroamérica
        'canal 5' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_5_HN_logo_2019.png?width=512',
        'canal 5 hon' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_5_HN_logo_2019.png?width=512',
        'canal 3 telesistemas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telesistema_Honduras_logo.png?width=512',
        'telesistemas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telesistema_Honduras_logo.png?width=512',
        'canal 6' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_6_Honduras.png?width=512',
        'canal 6 hn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_6_Honduras.png?width=512',
        'canal 8 tnh' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Televisi%C3%B3n_Nacional_de_Honduras_logo.png?width=512',
        'canal 8 tnh hn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Televisi%C3%B3n_Nacional_de_Honduras_logo.png?width=512',
        'tnh' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Televisi%C3%B3n_Nacional_de_Honduras_logo.png?width=512',
        'canal 10' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_10_Honduras_logo.png?width=512',
        'ten canal 10' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_10_Honduras_logo.png?width=512',
        'ten canal 10 hn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_10_Honduras_logo.png?width=512',
        'canal 11' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_11_Honduras_logo.png?width=512',
        'canal 11 hon' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Canal_11_Honduras_logo.png?width=512',
        'telecadena' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telecadena_7_y_4_logo.png?width=512',
        'telecadena hon' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telecadena_7_y_4_logo.png?width=512',
        'vtv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/VTV_Honduras_logo.png?width=512',
        'canal vtv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/VTV_Honduras_logo.png?width=512',
        'canal vtv hnd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/VTV_Honduras_logo.png?width=512',
        'hch' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HCH_logo.png?width=512',
        'hch hon' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HCH_logo.png?width=512',
        'suyapa tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Suyapa_TV_logo.png?width=512',
        'une tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/UNETV_logo.png?width=512',
        'qhubo tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Q%27hubo_TV_logo.png?width=512',
        'la liga hondurena' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Logo_Liga_Nacional_de_Honduras.png?width=512',
        'teletica' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Teletica_logo_2016.png?width=512',
        '7-teletica' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Teletica_logo_2016.png?width=512',
        'guatevision' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Guatevisi%C3%B3n_logo_2019.png?width=512',
        'telemicro' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telemicro_logo.png?width=512',
        'telefe' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telefe_2018.svg?width=512',
        'caracol' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Caracol_Televisi%C3%B3n_logo.svg?width=512',
        'tvchile' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TV_Chile_logo_2020.svg?width=512',
        'tve' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TVE_1_logo.svg?width=512',
        'antena 3' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Antena_3_2017.svg?width=512',
        'atres series' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Atreseries_logo.svg?width=512',
        'las estrellas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Las_Estrellas_logo.svg?width=512',
        'el canal de las estrellas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Las_Estrellas_logo.svg?width=512',
        'distrito comedia' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Distrito_Comedia_logo_2018.svg?width=512',
        'tlnovelas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TLNovelas_logo_2019.svg?width=512',
        'todo novelas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TLNovelas_logo_2019.svg?width=512',
        'rcn novelas' => 'https://commons.wikimedia.org/wiki/Special:FilePath/RCN_Novelas_logo.svg?width=512',
        'pasiones' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Pasiones_logo.svg?width=512',
        'az mundo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TV_Azteca_logo_2022.svg?width=512',
        'azcorazon' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TV_Azteca_logo_2022.svg?width=512',
        'az click' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TV_Azteca_logo_2022.svg?width=512',

        // Deportes
        'espn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn 2' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN2_wordmark.svg?width=512',
        'espn 2 hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN2_wordmark.svg?width=512',
        'espn 3' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN3_wordmark.svg?width=512',
        'espn 3 hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN3_wordmark.svg?width=512',
        'espn 4' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn 4 hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn 5' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn 5 hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_wordmark.svg?width=512',
        'espn premium' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESPN_Premium_logo.svg?width=512',
        'fox sports 3' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Fox_Sports_3_%282015%29.svg?width=512',
        'fox sports 3 hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Fox_Sports_3_%282015%29.svg?width=512',
        'being sport' => 'https://commons.wikimedia.org/wiki/Special:FilePath/BeIN_Sports_logo.svg?width=512',
        'bein sports' => 'https://commons.wikimedia.org/wiki/Special:FilePath/BeIN_Sports_logo.svg?width=512',
        'tigo sports' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Tigo_Sports_logo.png?width=512',
        'tigo sports hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Tigo_Sports_logo.png?width=512',
        'tudn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TUDN_logo.svg?width=512',
        'tudn mx' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TUDN_logo.svg?width=512',
        'golf channel' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Golf_Channel_logo.svg?width=512',
        'golf channel hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Golf_Channel_logo.svg?width=512',
        'b futbol total' => 'https://commons.wikimedia.org/wiki/Special:FilePath/BeIN_Sports_logo.svg?width=512',
        's sport la liga' => 'https://commons.wikimedia.org/wiki/Special:FilePath/LaLiga_EA_Sports_2023_Vertical_Logo.svg?width=512',
        'todo futbol 1' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TUDN_logo.svg?width=512',

        // Cine & Series
        'hbo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HBO_logo.svg?width=512',
        'hbo2' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HBO2_logo.svg?width=512',
        'hbo family' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HBO_Family_2014.svg?width=512',
        'hbo plus' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HBO_Plus_logo.svg?width=512',
        'hbo xtreme' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HBO_Xtreme_logo.svg?width=512',
        'a&e' => 'https://commons.wikimedia.org/wiki/Special:FilePath/A%26E_2017_logo.svg?width=512',
        'a&e hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/A%26E_2017_logo.svg?width=512',
        'amc' => 'https://commons.wikimedia.org/wiki/Special:FilePath/AMC_logo_2019.svg?width=512',
        'amc hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/AMC_logo_2019.svg?width=512',
        'axn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/AXN_logo_2016.svg?width=512',
        'axn hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/AXN_logo_2016.svg?width=512',
        'cinecanal' => 'https://commons.wikimedia.org/wiki/Special:FilePath/CinecanalLA.png?width=512',
        'cinecanal hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/CinecanalLA.png?width=512',
        'cinemax' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Cinemax_2016.svg?width=512',
        'cine premium' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Golden_Premier_logo.svg?width=512',
        'golden premier' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Golden_Premier_logo.svg?width=512',
        'comedy central' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Comedy_Central_2018.svg?width=512',
        'comedy central hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Comedy_Central_2018.svg?width=512',
        'film & arts' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Film%26Arts_logo_2016.svg?width=512',
        'fx' => 'https://commons.wikimedia.org/wiki/Special:FilePath/FX_Channel_Logo.svg?width=512',
        'fx hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/FX_Channel_Logo.svg?width=512',
        'paramount' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Paramount_Network_2018_logo.svg?width=512',
        'paramount hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Paramount_Network_2018_logo.svg?width=512',
        'sony' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Sony_Channel_logo.svg?width=512',
        'sony hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Sony_Channel_logo.svg?width=512',
        'sony movies' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Sony_Movies_logo.svg?width=512',
        'space' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Space_logo_2020.svg?width=512',
        'space hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Space_logo_2020.svg?width=512',
        'star channel' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Star_Channel_2021.svg?width=512',
        'star life' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Star_Life_logo.svg?width=512',
        'studio universal' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Studio_Universal_2016_logo.svg?width=512',
        'studio universal hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Studio_Universal_2016_logo.svg?width=512',
        'tcm' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TCM_2021_Logo.svg?width=512',
        'tnt' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TNT_Logo_2016.svg?width=512',
        'tnt hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TNT_Logo_2016.svg?width=512',
        'tnt series' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TNT_Series_logo_2016.svg?width=512',
        'tnt series hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TNT_Series_logo_2016.svg?width=512',
        'universal' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'universal premiere' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'universal cinema' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'universal comedy' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'universal crime' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'universal reality' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Universal_TV_logo.svg?width=512',
        'warner' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Warner_Channel_2023.svg?width=512',
        'warner hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Warner_Channel_2023.svg?width=512',
        'tru tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TruTV_2014_logo.svg?width=512',
        'europa' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Europa_Europa_logo.svg?width=512',

        // Infantiles
        'cartoon network' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Cartoon_Network_2010_logo.svg?width=512',
        'disney' => 'https://commons.wikimedia.org/wiki/Special:FilePath/2019_Disney_Channel_logo.svg?width=512',
        'disney jr' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Disney_Junior_2020.svg?width=512',
        'disney xd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Disney_XD_2015_logo.svg?width=512',
        'discovery kids' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_Kids_2021.svg?width=512',
        'baby tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/BabyTV_logo.svg?width=512',
        'boomerang' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Boomerang_2014_logo.svg?width=512',
        'clan' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Clan_TVE.svg?width=512',
        'nat geo kids' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Nat_Geo_Kids_logo.svg?width=512',
        'zoomoo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ZooMoo_logo.png?width=512',

        // Documentales & Variedades
        'discovery channel' => 'https://commons.wikimedia.org/wiki/Special:FilePath/2019_Discovery_logo.svg?width=512',
        'discovery channel hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/2019_Discovery_logo.svg?width=512',
        'disc theather' => 'https://commons.wikimedia.org/wiki/Special:FilePath/2019_Discovery_logo.svg?width=512',
        'disc theather hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/2019_Discovery_logo.svg?width=512',
        'discovery id' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Investigation_Discovery_2020.svg?width=512',
        'discovery id hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Investigation_Discovery_2020.svg?width=512',
        'discovery science' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_science_new_logo_2017.png?width=512',
        'discovery turbo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_Turbo_logo_2019.svg?width=512',
        'discovery world' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_World_logo.svg?width=512',
        'discovery world hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_World_logo.svg?width=512',
        'animal planet' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Animal_Planet_2018.svg?width=512',
        'animal planet hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Animal_Planet_2018.svg?width=512',
        'history' => 'https://commons.wikimedia.org/wiki/Special:FilePath/History_Logo.svg?width=512',
        'history hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/History_Logo.svg?width=512',
        'h2' => 'https://commons.wikimedia.org/wiki/Special:FilePath/H2_Latin_America.svg?width=512',
        'nat geo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/National_Geographic_Logo.svg?width=512',
        'nat geo hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/National_Geographic_Logo.svg?width=512',
        'national geographic' => 'https://commons.wikimedia.org/wiki/Special:FilePath/National_Geographic_Logo.svg?width=512',
        'home & health' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_Home_%26_Health_2019.svg?width=512',
        'home & health hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Discovery_Home_%26_Health_2019.svg?width=512',
        'tlc' => 'https://commons.wikimedia.org/wiki/Special:FilePath/TLC_Logo_2020.svg?width=512',
        'el gourmet' => 'https://commons.wikimedia.org/wiki/Special:FilePath/El_Gourmet_logo.svg?width=512',
        'mas chic' => 'https://commons.wikimedia.org/wiki/Special:FilePath/M%C3%A1s_Chic_logo.svg?width=512',
        'hola tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Hola_TV_logo.svg?width=512',
        'e! entertairment' => 'https://commons.wikimedia.org/wiki/Special:FilePath/E%21_logo_2012.svg?width=512',
        'life time' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Lifetime_logo_2020.svg?width=512',

        // Noticias & Internacional
        'cnn internacional' => 'https://commons.wikimedia.org/wiki/Special:FilePath/CNN.svg?width=512',
        'cnne' => 'https://commons.wikimedia.org/wiki/Special:FilePath/CNN_en_Espa%C3%B1ol_logo.svg?width=512',
        'telemundo' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telemundo_2018_logo.svg?width=512',
        'telemundo internacional' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telemundo_2018_logo.svg?width=512',
        'telemundo miami' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Telemundo_2018_logo.svg?width=512',
        'univision' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Univision_2019.svg?width=512',
        'univision hd' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Univision_2019.svg?width=512',
        'dw' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Deutsche_Welle_logo_2012.svg?width=512',
        'france24' => 'https://commons.wikimedia.org/wiki/Special:FilePath/France_24_logo.svg?width=512',
        'russia today' => 'https://commons.wikimedia.org/wiki/Special:FilePath/RT_logo.svg?width=512',

        // Música
        'mtv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/MTV_Logo_2021.svg?width=512',
        'htv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/HTV_2017.svg?width=512',
        'exa tv' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Exa_FM_logo.png?width=512',
        'sun channel' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Sun_Channel_logo.png?width=512',

        // Religiosos
        'ewtn' => 'https://commons.wikimedia.org/wiki/Special:FilePath/EWTN_logo.svg?width=512',
        'sembrador' => 'https://commons.wikimedia.org/wiki/Special:FilePath/ESNE_El_Sembrador_logo.png?width=512',
        'tbn enlace' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Enlace_logo_2016.png?width=512',
    ];

    /**
     * Asegura que el directorio local de almacenamiento exista.
     */
    public function ensureDirectoryExists(): void
    {
        Storage::disk($this->storageDisk)->makeDirectory($this->logoDirectory);
    }

    /**
     * Genera un nombre de archivo consistente basado en ID y slug del canal.
     * Ejemplo: ch_5_canal_5_hon.png
     */
    public function generateFilename(int|string $channelId, string $channelName, string $ext = 'png'): string
    {
        $slug = Str::slug($channelName, '_');
        if (empty($slug)) {
            $slug = 'channel';
        }
        $ext = ltrim($ext, '.');
        return "ch_{$channelId}_{$slug}.{$ext}";
    }

    /**
     * Normaliza el nombre del canal para comparaciones y búsquedas de logos.
     */
    public function normalizeName(string $name): string
    {
        $str = mb_strtolower($name, 'UTF-8');
        // Eliminar corchetes, paréntesis y su contenido
        $str = preg_replace('/\[[^\]]*\]|\([^\)]*\)/u', ' ', $str);
        // Quitar indicadores de calidad, códec o etiquetas comunes
        $str = preg_replace('/\b(hd|fhd|4k|sd|1080p|720p|hevc|h264|h265|latino|lat|esp|es|honduras|hon|hnd|mx|rd|gua|tv)\b/u', ' ', $str);
        // Quitar símbolos no alfanuméricos
        $str = preg_replace('/[^a-z0-9\s]/u', ' ', $str);
        return trim(preg_replace('/\s+/', ' ', $str));
    }

    /**
     * Resuelve y descarga el logo de un canal.
     * Guarda la imagen en storage público y retorna la URL local relativa (/storage/logos/channels/ch_X_slug.png).
     */
    public function processChannelLogo(TvChannel $channel, ?string $rawLogoUrl = null, bool $force = false): string
    {
        $this->ensureDirectoryExists();

        // 1. Verificar si ya existe un logo descargado previamente para este canal en disco (si no se fuerza)
        if (!$force) {
            $existing = $this->findExistingLocalLogo($channel->id);
            if ($existing) {
                return $existing;
            }
        }

        // 2. Determinar la mejor URL remota candidata
        $candidateUrl = $this->resolveCandidateLogoUrl($channel->name, $rawLogoUrl ?: $channel->logo_url, $channel->tvg_id);

        // 3. Descargar y persistir localmente si hay URL candidata
        if ($candidateUrl) {
            $localUrl = $this->downloadAndSaveLogo($candidateUrl, $channel->id, $channel->name);
            if ($localUrl) {
                return $localUrl;
            }
        }

        // 4. Si falló la descarga o no se encontró URL remota, generar un logo SVG estilizado localmente
        return $this->generateSvgLogoFallback($channel->id, $channel->name);
    }

    /**
     * Busca si ya existe un archivo de logo para este canal en storage local.
     */
    public function findExistingLocalLogo(int|string $channelId): ?string
    {
        $files = Storage::disk($this->storageDisk)->files($this->logoDirectory);

        foreach ($files as $file) {
            $basename = basename($file);
            if (str_starts_with($basename, "ch_{$channelId}_")) {
                $size = Storage::disk($this->storageDisk)->size($file);
                if ($size > 80) { // Archivo válido
                    return "/storage/{$file}";
                }
            }
        }

        return null;
    }

    /**
     * Resuelve la mejor URL remota para el canal:
     * - Si viene tvg-logo válido del M3U y no es de dominios caídos.
     * - Busca en diccionario curado.
     * - Busca en índice iptv-org.
     */
    public function resolveCandidateLogoUrl(string $channelName, ?string $m3uLogo = null, ?string $tvgId = null): ?string
    {
        // Si el M3U tiene un logo no roto (descartar tvlogos.b-cdn.net caído e imgur bloqueado)
        if (!empty($m3uLogo) && filter_var($m3uLogo, FILTER_VALIDATE_URL)) {
            if (!str_contains($m3uLogo, 'tvlogos.b-cdn.net') && !str_contains($m3uLogo, 'i.imgur.com') && !str_starts_with($m3uLogo, '/storage/')) {
                return $m3uLogo;
            }
        }

        $norm = $this->normalizeName($channelName);

        // 1. Coincidencia en diccionario curado (alta prioridad)
        if (isset($this->curatedLogos[$norm])) {
            return $this->curatedLogos[$norm];
        }

        // Coincidencia por palabra clave en diccionario curado
        foreach ($this->curatedLogos as $key => $url) {
            if (str_contains($norm, $key) || (strlen($key) >= 4 && str_contains($key, $norm))) {
                return $url;
            }
        }

        // 2. Coincidencia en base de datos de iptv-org
        $iptvLogo = $this->lookupIptvOrgLogo($norm, $tvgId);
        if ($iptvLogo) {
            return $iptvLogo;
        }

        return null;
    }

    /**
     * Busca en la base de datos de logos de iptv-org (usando caché persistente local).
     */
    protected function lookupIptvOrgLogo(string $normalizedName, ?string $tvgId = null): ?string
    {
        $index = $this->getIptvOrgIndex();
        if (empty($index)) {
            return null;
        }

        // Por tvg_id si está presente
        if ($tvgId && isset($index['by_id'][$tvgId])) {
            $u = $index['by_id'][$tvgId];
            if (!str_contains($u, 'i.imgur.com')) return $u;
        }

        // Por nombre normalizado
        $cleanNorm = preg_replace('/[^a-z0-9]/', '', $normalizedName);
        if (isset($index['by_name'][$cleanNorm])) {
            $u = $index['by_name'][$cleanNorm];
            if (!str_contains($u, 'i.imgur.com')) return $u;
        }

        // Búsqueda por token o palabra clave dentro de nombres indexados
        foreach ($index['by_name'] as $nameKey => $logoUrl) {
            if (str_contains($logoUrl, 'i.imgur.com')) continue;
            if (strlen($nameKey) >= 5 && (str_contains($cleanNorm, $nameKey) || str_contains($nameKey, $cleanNorm))) {
                return $logoUrl;
            }
        }

        return null;
    }

    /**
     * Obtiene el índice compilado de iptv-org (con caché local en storage).
     */
    protected function getIptvOrgIndex(): array
    {
        return Cache::remember('iptv_org_logo_index', 86400 * 3, function () {
            $cacheFile = storage_path('app/iptv_logos_index.json');
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < (86400 * 7)) {
                $decoded = json_decode(file_get_contents($cacheFile), true);
                if (is_array($decoded) && !empty($decoded['by_name'])) {
                    return $decoded;
                }
            }

            try {
                // Descargar logos.json y channels.json con timeout de 6s
                $logosRes = Http::timeout(6)->get('https://iptv-org.github.io/api/logos.json');
                $channelsRes = Http::timeout(6)->get('https://iptv-org.github.io/api/channels.json');

                if (!$logosRes->successful() || !$channelsRes->successful()) {
                    return ['by_id' => [], 'by_name' => []];
                }

                $logosData = $logosRes->json() ?: [];
                $channelsData = $channelsRes->json() ?: [];

                $byChannelId = [];
                foreach ($logosData as $item) {
                    $ch = $item['channel'] ?? '';
                    $u = $item['url'] ?? '';
                    if ($ch && $u && !str_contains($u, 'i.imgur.com') && !isset($byChannelId[$ch])) {
                        $byChannelId[$ch] = $u;
                    }
                }

                $byName = [];
                $byId = [];

                foreach ($channelsData as $item) {
                    $cid = $item['id'] ?? '';
                    if (isset($byChannelId[$cid])) {
                        $logoUrl = $byChannelId[$cid];
                        $byId[$cid] = $logoUrl;

                        $rawName = $item['name'] ?? '';
                        $clean = preg_replace('/[^a-z0-9]/', '', mb_strtolower($rawName, 'UTF-8'));
                        if ($clean && !isset($byName[$clean])) {
                            $byName[$clean] = $logoUrl;
                        }

                        foreach ($item['alt_names'] ?? [] as $alt) {
                            $altClean = preg_replace('/[^a-z0-9]/', '', mb_strtolower($alt, 'UTF-8'));
                            if ($altClean && !isset($byName[$altClean])) {
                                $byName[$altClean] = $logoUrl;
                            }
                        }
                    }
                }

                $compiled = ['by_id' => $byId, 'by_name' => $byName];
                @file_put_contents($cacheFile, json_encode($compiled));

                return $compiled;
            } catch (\Throwable $e) {
                Log::info("TvLogoService: No se pudo actualizar el índice remoto iptv-org: " . $e->getMessage());
                return ['by_id' => [], 'by_name' => []];
            }
        });
    }

    /**
     * Descarga la imagen desde la URL remota y la persiste en el disco local.
     * Retorna la ruta web relativa: /storage/logos/channels/ch_X_slug.ext
     */
    public function downloadAndSaveLogo(string $remoteUrl, int|string $channelId, string $channelName): ?string
    {
        try {
            $response = Http::timeout(4)
                ->withOptions([
                    'allow_redirects' => true,
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ])
                ->get($remoteUrl);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) < 100) {
                return null;
            }

            // Determinar extensión exacta por Magic Bytes del contenido y Content-Type real
            $ext = 'png';
            if (str_starts_with($body, "\x89PNG")) {
                $ext = 'png';
            } elseif (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
                $ext = 'webp';
            } elseif (str_starts_with($body, "\xFF\xD8\xFF")) {
                $ext = 'jpg';
            } elseif (str_starts_with(trim($body), '<svg') || str_contains(substr($body, 0, 300), '<svg')) {
                $ext = 'svg';
            } else {
                $contentType = strtolower($response->header('Content-Type') ?: '');
                if (str_contains($contentType, 'webp')) {
                    $ext = 'webp';
                } elseif (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
                    $ext = 'jpg';
                } elseif (str_contains($contentType, 'svg')) {
                    $ext = 'svg';
                }
            }

            $filename = $this->generateFilename($channelId, $channelName, $ext);
            $filePath = "{$this->logoDirectory}/{$filename}";

            Storage::disk($this->storageDisk)->put($filePath, $body);

            return "/storage/{$filePath}";
        } catch (\Throwable $e) {
            Log::debug("TvLogoService::downloadAndSaveLogo error en {$remoteUrl}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Genera un logotipo SVG local vectorizado y elegante como fallback definitivo.
     */
    public function generateSvgLogoFallback(int|string $channelId, string $channelName): string
    {
        $filename = $this->generateFilename($channelId, $channelName, 'svg');
        $filePath = "{$this->logoDirectory}/{$filename}";

        if (Storage::disk($this->storageDisk)->exists($filePath)) {
            return "/storage/{$filePath}";
        }

        // Extraer siglas o nombre corto
        $words = explode(' ', trim(preg_replace('/\b(hd|fhd|sd|4k)\b/i', '', $channelName)));
        $short = '';
        if (count($words) >= 2) {
            $short = mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1);
        } else {
            $short = mb_substr($words[0] ?? 'TV', 0, 3);
        }
        $short = mb_strtoupper($short);

        // Paleta elegante basada en hash del nombre
        $hash = crc32($channelName);
        $hue = abs($hash % 360);

        $escapedName = htmlspecialchars(mb_substr($channelName, 0, 18), ENT_XML1);
        $escapedShort = htmlspecialchars($short, ENT_XML1);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180" width="100%" height="100%">
  <defs>
    <linearGradient id="grad_{$channelId}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="hsl({$hue}, 65%, 25%)" />
      <stop offset="100%" stop-color="hsl({$hue}, 80%, 12%)" />
    </linearGradient>
  </defs>
  <rect width="320" height="180" rx="16" fill="url(#grad_{$channelId})" stroke="hsl({$hue}, 60%, 45%)" stroke-width="2" stroke-opacity="0.4" />
  <rect x="16" y="16" width="288" height="148" rx="10" fill="#000000" fill-opacity="0.25" />
  <text x="160" y="90" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="38" font-weight="900" fill="#ffffff" text-anchor="middle" dominant-baseline="middle" letter-spacing="2">
    {$escapedShort}
  </text>
  <text x="160" y="132" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="13" font-weight="600" fill="#e4e4e7" text-anchor="middle" opacity="0.9">
    {$escapedName}
  </text>
</svg>
SVG;

        Storage::disk($this->storageDisk)->put($filePath, $svg);

        return "/storage/{$filePath}";
    }

    /**
     * Procesa masivamente todos los canales de la base de datos.
     */
    public function syncAllChannelLogos(bool $force = false): array
    {
        $this->ensureDirectoryExists();

        if ($force) {
            // Limpiar archivos anteriores en logos/channels
            $oldFiles = Storage::disk($this->storageDisk)->files($this->logoDirectory);
            foreach ($oldFiles as $f) {
                Storage::disk($this->storageDisk)->delete($f);
            }
        }

        $channels = TvChannel::where('is_active', true)->get();

        $stats = [
            'total' => $channels->count(),
            'downloaded' => 0,
            'cached' => 0,
            'generated' => 0,
        ];

        foreach ($channels as $channel) {
            // Si no se fuerza y ya tiene un logo local existente en disco
            if (!$force) {
                $existing = $this->findExistingLocalLogo($channel->id);
                if ($existing) {
                    if ($channel->logo_url !== $existing) {
                        $channel->update(['logo_url' => $existing]);
                    }
                    $stats['cached']++;
                    continue;
                }
            }

            $currentLogo = $channel->logo_url;
            $localPath = $this->processChannelLogo($channel, $currentLogo, $force);

            if ($localPath) {
                $channel->update(['logo_url' => $localPath]);
                if (str_ends_with($localPath, '.svg') && !str_contains($currentLogo ?? '', '.svg')) {
                    $stats['generated']++;
                } else {
                    $stats['downloaded']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Despacha el comando de sincronización de logos en segundo plano (proceso asíncrono no bloqueante).
     */
    public function dispatchBackgroundSync(): void
    {
        try {
            $artisan = base_path('artisan');
            if (DIRECTORY_SEPARATOR === '\\') {
                pclose(popen("start /B php {$artisan} flixhn:sync-logos > NUL 2>&1", "r"));
            } else {
                exec("php {$artisan} flixhn:sync-logos > /dev/null 2>&1 &");
            }
        } catch (\Throwable $e) {
            Log::info("TvLogoService: No se pudo lanzar proceso en background: " . $e->getMessage());
        }
    }
}
