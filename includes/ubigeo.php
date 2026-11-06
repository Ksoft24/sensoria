<?php
/**
 * Ubigeo Peru (departamento => [provincias]).
 * El distrito se deja como campo de texto libre en el formulario para no
 * inflar este archivo con ~1800 distritos; si mas adelante quieres el
 * listado completo de distritos, se puede anidar un tercer nivel aqui
 * mismo (provincia => [distritos]) sin tocar el resto del codigo, ya que
 * el JS de registro.php ya esta armado para leer estructuras anidadas.
 */
return [
    'Amazonas' => ['Chachapoyas', 'Bagua', 'Bongará', 'Condorcanqui', 'Luya', 'Rodríguez de Mendoza', 'Utcubamba'],
    'Áncash' => ['Huaraz', 'Aija', 'Antonio Raymondi', 'Asunción', 'Bolognesi', 'Carhuaz', 'Carlos Fermín Fitzcarrald', 'Casma', 'Corongo', 'Huari', 'Huarmey', 'Huaylas', 'Mariscal Luzuriaga', 'Ocros', 'Pallasca', 'Pomabamba', 'Recuay', 'Santa', 'Sihuas', 'Yungay'],
    'Apurímac' => ['Abancay', 'Andahuaylas', 'Antabamba', 'Aymaraes', 'Cotabambas', 'Chincheros', 'Grau'],
    'Arequipa' => ['Arequipa', 'Camaná', 'Caravelí', 'Castilla', 'Caylloma', 'Condesuyos', 'Islay', 'La Unión'],
    'Ayacucho' => ['Huamanga', 'Cangallo', 'Huanca Sancos', 'Huanta', 'La Mar', 'Lucanas', 'Parinacochas', 'Páucar del Sara Sara', 'Sucre', 'Víctor Fajardo', 'Vilcas Huamán'],
    'Cajamarca' => ['Cajamarca', 'Cajabamba', 'Celendín', 'Chota', 'Contumazá', 'Cutervo', 'Hualgayoc', 'Jaén', 'San Ignacio', 'San Marcos', 'San Miguel', 'San Pablo', 'Santa Cruz'],
    'Callao' => ['Callao'],
    'Cusco' => ['Cusco', 'Acomayo', 'Anta', 'Calca', 'Canas', 'Canchis', 'Chumbivilcas', 'Espinar', 'La Convención', 'Paruro', 'Paucartambo', 'Quispicanchi', 'Urubamba'],
    'Huancavelica' => ['Huancavelica', 'Acobamba', 'Angaraes', 'Castrovirreyna', 'Churcampa', 'Huaytará', 'Tayacaja'],
    'Huánuco' => ['Huánuco', 'Ambo', 'Dos de Mayo', 'Huacaybamba', 'Huamalíes', 'Leoncio Prado', 'Marañón', 'Pachitea', 'Puerto Inca', 'Lauricocha', 'Yarowilca'],
    'Ica' => ['Ica', 'Chincha', 'Nazca', 'Palpa', 'Pisco'],
    'Junín' => ['Huancayo', 'Chanchamayo', 'Chupaca', 'Concepción', 'Jauja', 'Junín', 'Satipo', 'Tarma', 'Yauli'],
    'La Libertad' => ['Trujillo', 'Ascope', 'Bolívar', 'Chepén', 'Julcán', 'Otuzco', 'Pacasmayo', 'Pataz', 'Sánchez Carrión', 'Santiago de Chuco', 'Gran Chimú', 'Virú'],
    'Lambayeque' => ['Chiclayo', 'Ferreñafe', 'Lambayeque'],
    'Lima' => ['Lima', 'Barranca', 'Cajatambo', 'Canta', 'Cañete', 'Huaral', 'Huarochirí', 'Huaura', 'Oyón', 'Yauyos'],
    'Loreto' => ['Maynas', 'Alto Amazonas', 'Datem del Marañón', 'Loreto', 'Mariscal Ramón Castilla', 'Putumayo', 'Requena', 'Ucayali'],
    'Madre de Dios' => ['Tambopata', 'Manu', 'Tahuamanu'],
    'Moquegua' => ['Mariscal Nieto', 'General Sánchez Cerro', 'Ilo'],
    'Pasco' => ['Pasco', 'Daniel Alcides Carrión', 'Oxapampa'],
    'Piura' => ['Piura', 'Ayabaca', 'Huancabamba', 'Morropón', 'Paita', 'Sechura', 'Sullana', 'Talara'],
    'Puno' => ['Puno', 'Azángaro', 'Carabaya', 'Chucuito', 'El Collao', 'Huancané', 'Lampa', 'Melgar', 'Moho', 'San Antonio de Putina', 'San Román', 'Sandia', 'Yunguyo'],
    'San Martín' => ['Moyobamba', 'Bellavista', 'El Dorado', 'Huallaga', 'Lamas', 'Mariscal Cáceres', 'Picota', 'Rioja', 'San Martín', 'Tocache'],
    'Tacna' => ['Tacna', 'Candarave', 'Jorge Basadre', 'Tarata'],
    'Tumbes' => ['Tumbes', 'Contralmirante Villar', 'Zarumilla'],
    'Ucayali' => ['Coronel Portillo', 'Atalaya', 'Padre Abad', 'Purús'],
];
