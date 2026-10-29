<?php

namespace App\Services;

use Illuminate\Support\Str;

class OttCatalogRegistry
{
    /**
     * Catálogo curado oficial de estrenos y producciones originales por plataforma OTT
     */
    public static function getAllSamples(): array
    {
        return [
            'netflix' => [
                [
                    'id' => 'nflx-81915745',
                    'title' => 'El botín',
                    'original_title' => 'The Rip',
                    'year' => 2026,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://m.media-amazon.com/images/M/MV5BNjIzMGY3MzMtNDVlMS00MGU1LTkyNTItMmI4Mzk0Mjg3OTBkXkEyXkFqcGc@._V1_.jpg',
                    'backdrop' => 'https://m.media-amazon.com/images/M/MV5BNjIzMGY3MzMtNDVlMS00MGU1LTkyNTItMmI4Mzk0Mjg3OTBkXkEyXkFqcGc@._V1_.jpg',
                    'overview' => 'En este electrizante thriller inspirado en hechos reales, la tensión crece y la confianza tambalea cuando cinco policías de Miami encuentran millones de dólares en efectivo.',
                    'actors' => 'Matt Damon, Ben Affleck, Steven Yeun, Teyana Taylor, Kyle Chandler',
                    'genre' => 'De intriga / Acción',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-the-witcher',
                    'title' => 'The Witcher',
                    'original_title' => 'The Witcher',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/7WsyChQLEftFiDOVTGkv3hFpyyt.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/jBJWaqoSCiARWtfV0GlqHrcdidd.jpg',
                    'overview' => 'Geralt de Rivia, un cazador de monstruos mutado, viaja en pos de su destino por un mundo turbulento donde las personas suelen ser más crueles que las bestias.',
                    'actors' => 'Henry Cavill, Freya Allan, Anya Chalotra',
                    'genre' => 'Fantasía / Aventura',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-stranger-things',
                    'title' => 'Stranger Things',
                    'original_title' => 'Stranger Things',
                    'year' => 2025,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/49WJfeN0moxb9IPfGn8AIqMGskD.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/56v2KjBlU4XaOv9rVYEQypROD7P.jpg',
                    'overview' => 'Cuando un niño desaparece, sus amigos, la familia y la policía se ven envueltos en un misterio que involucra experimentos secretos, fuerzas sobrenaturales y una niña con poderes.',
                    'actors' => 'Millie Bobby Brown, Finn Wolfhard, Winona Ryder',
                    'genre' => 'Ciencia Ficción / Drama',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-merlina',
                    'title' => 'Merlina',
                    'original_title' => 'Wednesday',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/iHSwvRVsRyxpX7FE7GbviaDvgGZ.jpg',
                    'overview' => 'Inteligente, sarcástica y un poco muerta por dentro, Merlina Addams investiga una ola de asesinatos mientras hace nuevos amigos en la Academia Nunca Más.',
                    'actors' => 'Jenna Ortega, Gwendoline Christie, Riki Lindhome',
                    'genre' => 'Fantasía / Comedia Negra',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-squid-game',
                    'title' => 'El Juego del Calamar',
                    'original_title' => 'Squid Game',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/dDlGcaqftV2zX9G97R435W4P9vA.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/oaGvjB0DvdurWhf9IhStbtQg9d4.jpg',
                    'overview' => 'Cientos de personas con dificultades económicas aceptan una extraña invitación para competir en juegos infantiles. Adentro les esperan desafíos letales de supervivencia.',
                    'actors' => 'Lee Jung-jae, Park Hae-soo, Wi Ha-joon',
                    'genre' => 'Suspense / Drama',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-rebel-ridge',
                    'title' => 'Rebel Ridge',
                    'original_title' => 'Rebel Ridge',
                    'year' => 2024,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/xEt2GSz9z5rSVpIHMiGdtf0KLvd.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/417tYZ4XUyJrtyZXj7HSD0Zapkr.jpg',
                    'overview' => 'Un exmarine se enfrenta a una red de corrupción policial en un pequeño pueblo después de que le incautan injustamente los ahorros para pagar la fianza de su primo.',
                    'actors' => 'Aaron Pierre, Don Johnson, AnnaSophia Robb',
                    'genre' => 'Acción / Suspense / Crimen',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-extraction-2',
                    'title' => 'Misión de Rescate 2',
                    'original_title' => 'Extraction 2',
                    'year' => 2023,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/7gKI9hpEMcZUwYeCuVmACQC9Y42.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/7I6VUdPj6tQECNHdviJkUHD2389.jpg',
                    'overview' => 'Tras sobrevivir a su última misión, el mercenario Tyler Rake se enfrenta a un desafío aún más peligroso: rescatar a la familia de un despiadado criminal.',
                    'actors' => 'Chris Hemsworth, Golshifteh Farahani, Adam Bessa',
                    'genre' => 'Acción / Suspense',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-arcane',
                    'title' => 'Arcane',
                    'original_title' => 'Arcane',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/abf8tHznhSvl9BAElD2cQYRrQI2.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/fqldf2t8ztc9aiwn397FvFeUQgr.jpg',
                    'overview' => 'En medio del conflicto entre Piltóver y Zaun, dos hermanas luchan en bandos opuestos de una guerra entre tecnologías mágicas.',
                    'actors' => 'Hailee Steinfeld, Ella Purnell, Kevin Alejandro',
                    'genre' => 'Animación / Ciencia Ficción',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-lupin',
                    'title' => 'Lupin',
                    'original_title' => 'Lupin',
                    'year' => 2023,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/sgxawbFB5Vi5OkPWQLNxd3PwhDK.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/m99Fsm0Lg7g9F2H8t1T5gJ7aM9Q.jpg',
                    'overview' => 'Inspirado en las aventuras de Arsenio Lupin, el caballero y ladrón Assane Diop se propone vengar la injusticia sufrida por su padre.',
                    'actors' => 'Omar Sy, Ludivine Sagnier, Antoine Gouy',
                    'genre' => 'Crimen / Drama / Misterio',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-berlin',
                    'title' => 'Berlín',
                    'original_title' => 'Berlin',
                    'year' => 2023,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/yKAg9dGg16N1fWnK1x4Lh4R9kX7.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/bF8eY6Z7Hh1q0K7r4T1J4N9kL8.jpg',
                    'overview' => 'En su época dorada, Berlín se reúne con una banda en París para planear uno de sus mayores golpes: robar una colección de joyas valorada en 44 millones de euros.',
                    'actors' => 'Pedro Alonso, Michelle Jenner, Tristán Ulloa',
                    'genre' => 'Acción / Suspense / Crimen',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-la-sociedad-de-la-nieve',
                    'title' => 'La sociedad de la nieve',
                    'original_title' => 'Society of the Snow',
                    'year' => 2023,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/6A5Krn8ZkO6W47U5bZt9gK9p2k4.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/9J2OqZ5tO2aT00oZgVjKx7zP5j8.jpg',
                    'overview' => 'En 1972, un vuelo procedente de Uruguay se estrella en el corazón de los Andes. Solo 29 de sus 45 pasajeros sobreviven al trágico impacto.',
                    'actors' => 'Enzo Vogrincic, Agustín Pardella, Matías Recalt',
                    'genre' => 'Aventura / Drama / Historia',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-dejar-el-mundo-atras',
                    'title' => 'Dejar el mundo atrás',
                    'original_title' => 'Leave the World Behind',
                    'year' => 2023,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/5qHoazZiaLe7oFBok7XlUhg96f2.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/2KGxQFV9Wp1MshPBf8BuqW0VAu3.jpg',
                    'overview' => 'Las vacaciones de una familia en una lujosa casa de alquiler se ven interrumpidas por un ciberataque que deja incomunicados sus dispositivos y la llegada de dos extraños.',
                    'actors' => 'Julia Roberts, Mahershala Ali, Ethan Hawke',
                    'genre' => 'Misterio / Suspense / Drama',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-alerta-roja',
                    'title' => 'Alerta Roja',
                    'original_title' => 'Red Notice',
                    'year' => 2021,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/lAXONuqgQP2HGvAkMTMxHQioWVE.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/dK12GId1DS6AqYOTFdHRLg9GQKE.jpg',
                    'overview' => 'Un agente del FBI debe aliarse a regañadientes con el ladrón de arte más buscado del mundo para atrapar a una elusiva criminal.',
                    'actors' => 'Dwayne Johnson, Ryan Reynolds, Gal Gadot',
                    'genre' => 'Acción / Comedia / Crimen',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-one-piece',
                    'title' => 'One Piece',
                    'original_title' => 'One Piece',
                    'year' => 2023,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/fcXdJlbSdUEeMSJFsXKsznGwwok.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/4XM8DUTQb3lhLemJC51Jx4a2EuA.jpg',
                    'overview' => 'Monkey D. Luffy emprende un viaje con su tripulación pirata para encontrar el legendario tesoro conocido como One Piece.',
                    'actors' => 'Iñaki Godoy, Emily Rudd, Mackenyu',
                    'genre' => 'Acción / Aventura / Fantasía',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'nflx-peaky-blinders',
                    'title' => 'Peaky Blinders',
                    'original_title' => 'Peaky Blinders',
                    'year' => 2022,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/vUUqzWa2LnHIVqkaKVlVGkVcZIW.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/w7BYc1h1hU2y9Kq9y8v7u7.jpg',
                    'overview' => 'Gran Bretaña, 1919. Thomas Shelby lidera una conocida banda de Birmingham que prospera gracias a las apuestas ilegales y la extorsión.',
                    'actors' => 'Cillian Murphy, Paul Anderson, Helen McCrory',
                    'genre' => 'Crimen / Drama',
                    'quality' => '4K HDR',
                ]
            ],
            'disney' => [
                [
                    'id' => 'dis-deadpool-wolverine',
                    'title' => 'Deadpool & Wolverine',
                    'original_title' => 'Deadpool & Wolverine',
                    'year' => 2024,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/8cdWjvZQUExUUTzyp4t6EDMubfO.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/yDHYTfA3R0jFYba16jBB1jv8uaC.jpg',
                    'overview' => 'Un apático Wade Wilson se afana en la vida civil dejando atrás sus días como Deadpool. Pero cuando su mundo natal se enfrenta a una amenaza existencial, vuelve a la acción con Wolverine.',
                    'actors' => 'Ryan Reynolds, Hugh Jackman, Emma Corrin',
                    'genre' => 'Acción / Comedia / Ciencia Ficción',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'dis-loki',
                    'title' => 'Loki',
                    'original_title' => 'Loki',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/voHUmlvjysv923xao5iJDmeM6Bq.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/bZGA55Xk59T4gZ8UvT3D1Kx6y7v.jpg',
                    'overview' => 'El voluble villano Loki retoma su papel como Dios de las mentiras en una nueva serie que transcurre tras los acontecimientos de Avengers: Endgame.',
                    'actors' => 'Tom Hiddleston, Owen Wilson, Sophia Di Martino',
                    'genre' => 'Ciencia Ficción / Aventura',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'dis-avatar-way-of-water',
                    'title' => 'Avatar: El camino del agua',
                    'original_title' => 'Avatar: The Way of Water',
                    'year' => 2023,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMCA60NsSmeqe9RmNV.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/s16H6tpK2utvwDtzZ8Qy4qm5Emw.jpg',
                    'overview' => 'Jake Sully y Neytiri han formado una familia y hacen todo lo posible por permanecer juntos. Sin embargo, deben abandonar su hogar y explorar las regiones de Pandora.',
                    'actors' => 'Sam Worthington, Zoe Saldaña, Sigourney Weaver',
                    'genre' => 'Ciencia Ficción / Aventura / Acción',
                    'quality' => '4K HDR',
                ]
            ],
            'hbo_max' => [
                [
                    'id' => 'hbo-house-of-dragon',
                    'title' => 'La Casa del Dragón',
                    'original_title' => 'House of the Dragon',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/1XDDXPXGiI8id7MrUxK36ke7gkX.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/etj5CuMuam3hD9P9G4zg0J2026.jpg',
                    'overview' => 'La historia de la Casa Targaryen, ambientada 200 años antes de los eventos de Game of Thrones.',
                    'actors' => 'Emma D\'Arcy, Matt Smith, Olivia Cooke',
                    'genre' => 'Drama / Fantasía / Acción',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'hbo-dune-part-two',
                    'title' => 'Dune: Parte Dos',
                    'original_title' => 'Dune: Part Two',
                    'year' => 2024,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/1pdfLvkbY9ohJlCjQH2CZjjYVvJ.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/xOMo8BRK7PfcJv9JCnx7s5hj0x2.jpg',
                    'overview' => 'Paul Atreides se une a Chani y a los Fremen mientras busca venganza contra los conspiradores que destruyeron a su familia.',
                    'actors' => 'Timothée Chalamet, Zendaya, Rebecca Ferguson',
                    'genre' => 'Ciencia Ficción / Aventura',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'hbo-the-last-of-us',
                    'title' => 'The Last of Us',
                    'original_title' => 'The Last of Us',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/uKvVjHNqB5VmOrdxqMisytaq9m.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/uDgy6hyPd82kOHh6I95FLtLnj6p.jpg',
                    'overview' => 'Veinte años después de que una plaga destruyera la civilización, Joel es contratado para sacar de contrabando a Ellie, una niña de 14 años, de una zona de cuarentena.',
                    'actors' => 'Pedro Pascal, Bella Ramsey, Gabriel Luna',
                    'genre' => 'Drama / Ciencia Ficción / Acción',
                    'quality' => '4K HDR',
                ]
            ],
            'amazon_prime' => [
                [
                    'id' => 'prm-the-boys',
                    'title' => 'The Boys',
                    'original_title' => 'The Boys',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/mY7SeH4YFFxWv167RIIz3el9mNL.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/nxxCPRgtzxUH8SFMr4g4Bp1vB42.jpg',
                    'overview' => 'Un grupo de vigilantes conocidos informalmente como The Boys se propone derribar a superhéroes corruptos que abusan de sus superpoderes.',
                    'actors' => 'Karl Urban, Jack Quaid, Antony Starr',
                    'genre' => 'Acción / Comedia Negra / Ciencia Ficción',
                    'quality' => '4K HDR',
                ],
                [
                    'id' => 'prm-fallout',
                    'title' => 'Fallout',
                    'original_title' => 'Fallout',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/AnsZuLp7vQY00O5wQ7KjQ3F3K6.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/2wkV2q9W92k3y8U4w6x7zP5j8.jpg',
                    'overview' => 'En un futuro postapocalíptico, los habitantes de lujosos refugios nucleares se ven obligados a regresar al paisaje irradiado que sus ancestros dejaron atrás.',
                    'actors' => 'Ella Purnell, Walton Goggins, Aaron Moten',
                    'genre' => 'Ciencia Ficción / Acción / Aventura',
                    'quality' => '4K HDR',
                ]
            ],
            'apple_tv' => [
                [
                    'id' => 'apl-severance',
                    'title' => 'Severance',
                    'original_title' => 'Severance',
                    'year' => 2024,
                    'type' => 'Serie',
                    'media_type' => 'series',
                    'poster' => 'https://image.tmdb.org/t/p/w500/u3bZgnGQ9T01sWNhyveQz0wH0Hl.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/9J2OqZ5tO2aT00oZgVjKx7zP5j8.jpg',
                    'overview' => 'Mark lidera un equipo de empleados cuyos recuerdos se dividieron quirúrgicamente entre su vida laboral y personal.',
                    'actors' => 'Adam Scott, Patricia Arquette, John Turturro',
                    'genre' => 'Ciencia Ficción / Suspense / Drama',
                    'quality' => '4K HDR',
                ]
            ],
            'paramount' => [
                [
                    'id' => 'par-top-gun-maverick',
                    'title' => 'Top Gun: Maverick',
                    'original_title' => 'Top Gun: Maverick',
                    'year' => 2022,
                    'type' => 'Película',
                    'media_type' => 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w500/62HCnUTziyWcpDaBO2i1DX17ljH.jpg',
                    'backdrop' => 'https://image.tmdb.org/t/p/original/odJ4hx6g6vBt4lBWKFD1tqItuoO.jpg',
                    'overview' => 'Tras más de treinta años de servicio como uno de los mejores aviadores de la Armada, Pete "Maverick" Mitchell entrena a un destacamento de graduados de TOPGUN.',
                    'actors' => 'Tom Cruise, Miles Teller, Jennifer Connelly',
                    'genre' => 'Acción / Drama',
                    'quality' => '4K HDR',
                ]
            ]
        ];
    }

    /**
     * Devuelve los títulos curados de una plataforma formateados para FlixGrab
     */
    public static function getPlatformSamples(string $platform): array
    {
        $all = self::getAllSamples();
        $key = strtolower(trim($platform));
        if ($key === 'disney_plus') $key = 'disney';

        $items = $all[$key] ?? ($all['netflix'] ?? []);
        $platformLabel = strtoupper($key === 'disney' ? 'Disney+' : ($key === 'hbo_max' ? 'Max (HBO)' : $key));

        $formatted = [];
        foreach ($items as $item) {
            $slug = Str::slug($item['title']);
            $uniqueId = (string) $item['id'];
            $filePath = "/var/www/html/storage/media/{$key}/{$uniqueId}/{$slug}.mp4";

            $formatted[] = [
                'id'                => $uniqueId,
                'unique_id'         => $uniqueId,
                'title'             => $item['title'],
                'original_title'    => $item['original_title'],
                'type'              => $item['type'],
                'media_type'        => $item['media_type'],
                'year'              => (int) $item['year'],
                'poster'            => $item['poster'],
                'poster_url'        => $item['poster'],
                'poster_path'       => $item['poster'],
                'platform'          => $platformLabel,
                'provider'          => $key,
                'quality'           => $item['quality'] ?? '4K HDR',
                'quality_available' => $item['quality'] ?? '4K HDR',
                'overview'          => $item['overview'],
                'synopsis'          => $item['overview'],
                'genre'             => $item['genre'] ?? ($item['media_type'] === 'series' ? 'Serie Original' : 'Largometraje'),
                'actors'            => $item['actors'] ?? '',
                'localFilePath'     => $filePath,
                'filePath'          => $filePath,
                'local_file_path'   => $filePath,
                'file_path'         => $filePath,
                'available'         => false,
                'is_already_downloaded' => false,
                'is_downloading'    => false,
                'is_in_queue'       => false,
            ];
        }

        return $formatted;
    }

    /**
     * Búsqueda en el catálogo remoto REAL de la plataforma especificada
     */
    public static function search(string $query, string $platform = 'netflix'): array
    {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return [];
        }

        $normPlatform = strtolower(trim($platform));
        if ($normPlatform === 'disney_plus') $normPlatform = 'disney';

        $lowerQ = mb_strtolower($cleanQuery, 'UTF-8');
        $asciiQ = Str::ascii($lowerQ);

        $platformSamples = self::getPlatformSamples($normPlatform);
        $matches = [];

        // 1. Coincidencia en catálogo curado oficial con normalización de tildes (Str::ascii)
        foreach ($platformSamples as $item) {
            $tAscii = Str::ascii(mb_strtolower($item['title'], 'UTF-8'));
            $oAscii = Str::ascii(mb_strtolower($item['original_title'], 'UTF-8'));

            if (str_contains($tAscii, $asciiQ) || str_contains($oAscii, $asciiQ) || str_contains($asciiQ, $tAscii)) {
                $matches[] = $item;
            }
        }

        // 2. Si no hubo coincidencia en el catálogo curado, consultar la API en vivo de sugerencias de IMDb
        if (empty($matches)) {
            $cleanStr = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $cleanQuery));
            $firstChar = substr($cleanStr, 0, 1);

            if (!empty($cleanStr) && !empty($firstChar)) {
                $url = "https://v3.sg.media-imdb.com/suggestion/{$firstChar}/{$cleanStr}.json";
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $res = curl_exec($ch);
                curl_close($ch);

                if ($res) {
                    $data = json_decode($res, true);
                    foreach ($data['d'] ?? [] as $imdb) {
                        $qType = $imdb['q'] ?? '';
                        if (!in_array($qType, ['feature', 'TV series', 'TV mini-series', 'TV movie', 'movie'])) {
                            continue;
                        }

                        $title = $imdb['l'] ?? '';
                        $poster = $imdb['i']['imageUrl'] ?? null;
                        if (!$poster) continue;

                        $titleAscii = Str::ascii(strtolower($title));
                        $isMatch = false;

                        // Verificar correspondencia real de palabras para evitar falsos positivos
                        if (str_contains($titleAscii, $asciiQ) || str_contains($asciiQ, $titleAscii)) {
                            $isMatch = true;
                        } else {
                            $words = explode(' ', $cleanQuery);
                            foreach ($words as $w) {
                                $wAscii = Str::ascii(strtolower($w));
                                if (strlen($wAscii) >= 3 && str_contains($titleAscii, $wAscii)) {
                                    $isMatch = true;
                                    break;
                                }
                            }
                        }

                        if ($isMatch) {
                            $isSeries = str_contains(strtolower($qType), 'series');
                            $uniqueId = 'nflx-' . ($imdb['id'] ?? uniqid());
                            $slug = Str::slug($title);
                            $filePath = "/var/www/html/storage/media/{$normPlatform}/{$uniqueId}/{$slug}.mp4";
                            $platformLabel = strtoupper($normPlatform === 'disney' ? 'Disney+' : ($normPlatform === 'hbo_max' ? 'Max (HBO)' : $normPlatform));

                            $matches[] = [
                                'id'                => $uniqueId,
                                'unique_id'         => $uniqueId,
                                'title'             => $title,
                                'original_title'    => $title,
                                'year'              => (int) ($imdb['y'] ?? date('Y')),
                                'type'              => $isSeries ? 'Serie' : 'Película',
                                'media_type'        => $isSeries ? 'series' : 'movie',
                                'poster'            => $poster,
                                'poster_url'        => $poster,
                                'poster_path'       => $poster,
                                'platform'          => $platformLabel,
                                'provider'          => $normPlatform,
                                'quality'           => '4K HDR',
                                'quality_available' => '4K HDR',
                                'overview'          => "Contenido disponible en el catálogo de {$platformLabel}. Con " . ($imdb['s'] ?? 'elenco principal') . ".",
                                'synopsis'          => "Contenido disponible en el catálogo de {$platformLabel}. Con " . ($imdb['s'] ?? 'elenco principal') . ".",
                                'actors'            => $imdb['s'] ?? '',
                                'genre'             => $isSeries ? 'Serie Original' : 'Largometraje',
                                'localFilePath'     => $filePath,
                                'filePath'          => $filePath,
                                'local_file_path'   => $filePath,
                                'file_path'         => $filePath,
                                'available'         => false,
                                'is_already_downloaded' => false,
                                'is_downloading'    => false,
                                'is_in_queue'       => false,
                            ];
                        }
                    }
                }
            }
        }

        return $matches;
    }
}
