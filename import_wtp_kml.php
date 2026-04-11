<?php
/**
 * import_wtp_kml.php – Einmalig-Import der KML-Daten
 *
 * WICHTIG: Diese Datei nach dem Import sofort löschen!
 *
 * Aufruf: https://deine-domain.ch/import_wtp_kml.php?dept_id=1
 * dept_id = ID der Feuerwehr aus der Tabelle fire_departments
 * (nach setup.php einloggen und ID in der DB nachschauen)
 */

require_once __DIR__ . '/config/auth_helper.php';

// ─── Konfiguration ─────────────────────────────────────────────────────────
// Department-ID aus URL-Parameter (Pflicht)
$deptId = (int) ($_GET['dept_id'] ?? 0);
if ($deptId === 0) {
    die('<p style="font:16px sans-serif;color:red">Fehler: ?dept_id=X fehlt in der URL.</p>');
}

// ─── KML-Daten (alle Placemarks als PHP-Array) ─────────────────────────────
// Format: [name, lat, lng]
// Koordinaten aus KML: lng,lat → hier als lat,lng gespeichert
$placemarks = [
    ['WT Plan 1 Rotebode', 47.0520579, 8.1164202],
    ['WT Plan 1 Schauisfeld', 47.0514146, 8.1155994],
    ['WT Plan 1 Stäghüsli 1', 47.049984, 8.1157931],
    ['WT Plan 1 Stäghüsli 2', 47.0484415, 8.1160023],
    ['WT Plan 1 Langnau 1+2', 47.0479663, 8.1191458],
    ['WT Plan 2 Langnau 3+4', 47.0468251, 8.1206923],
    ['WT Plan 2 Näbdeflue', 47.0493383, 8.1228457],
    ['WT Plan 2 Bäreweid', 47.0480992, 8.1259463],
    ['WT Plan 2 Hasewald', 47.0472949, 8.1278668],
    ['WT Plan 3 Bäre 1+2', 47.0483716, 8.1308857],
    ['WT Plan 3 Hasehus', 47.0476625, 8.132538],
    ['WT Plan 3 Eggli', 47.0461565, 8.131862],
    ['WT Plan 3 Muffenhaus', 47.0446847, 8.1350861],
    ['WT Plan 3 Muffehus', 47.0444142, 8.1333051],
    ['WT Plan 3 Untersteiglen', 47.0456571, 8.135137],
    ['WT Plan 3 Obersteiglen', 47.0475228, 8.1362745],
    ['WT Plan 3 Chuderbode', 47.0471865, 8.1394073],
    ['WT Plan 3 Chuderhaus', 47.0453442, 8.1388279],
    ['WT Plan 3 Kesslerhüsli', 47.0447949, 8.1414593],
    ['WT Plan 3, 37 Berghalde', 47.0437713, 8.1413949],
    ['WT Plan 3, 37 Chierihus', 47.0418619, 8.1422557],
    ['WT Plan 4 Bergrösli', 47.0415161, 8.1462559],
    ['WT Plan 4 Unterschliferhüsli', 47.0424304, 8.1454717],
    ['WT Plan 5 Birrenhüsli', 47.0446997, 8.1457704],
    ['WT Plan 4 Geisschachen', 47.0400663, 8.149184],
    ['WT Plan 5 Ammergehrige 11, 12', 47.0435023, 8.1491696],
    ['WT Plan 5 Ammehrgerige 10', 47.0434, 8.1519484],
    ['WT Plan 5 Ammehrgerige 8, 9', 47.0434899, 8.153602],
    ['WT Plan 5 Ammehrgerige 6, 7', 47.0450617, 8.1600393],
    ['WT Plan 5 Vorderschlucht', 47.0472274, 8.1593772],
    ['WT Plan 5 Hinterschluchtn', 47.0464087, 8.1553217],
    ['WT Plan 5 Grosstschepperslehn', 47.0469121, 8.1514573],
    ['WT Plan 5 Kleintschepperslehn', 47.0465064, 8.1491667],
    ['WT Plan 5 Tschepperslehn', 47.048435, 8.1499754],
    ['WT Plan 5 Grabe 2, 3', 47.0501801, 8.1579462],
    ['WT Plan 6 Stäghalde 2', 47.040429, 8.1555039],
    ['WT Plan 6 Schönebodehalde', 47.0398294, 8.1549675],
    ['WT Plan 6 Steghalde 1', 47.0400853, 8.1523711],
    ['WT Plan 6 Schönebode 1', 47.0385739, 8.1575173],
    ['WT Plan 6 Schönebode 2', 47.0386543, 8.1601565],
    ['WT Plan 7 Schönebode 3', 47.0386753, 8.1623086],
    ['WT Plan 7 Oberbruchenhalden 1', 47.0414128, 8.1609537],
    ['WT Plan 7 Steinhalden 1, 2', 47.0420123, 8.1648429],
    ['WT Plan 7 Löchlihalde', 47.0413393, 8.1671874],
    ['WT Plan 7 Ammehrgerige 4, 5', 47.0451582, 8.162419],
    ['WT Plan 7 Ammehrgerige 3', 47.0451764, 8.163744],
    ['WT Plan 8 Holzhalde', 47.0480935, 8.1655286],
    ['WT Plan 8 Breithueb', 47.050155, 8.1698094],
    ['WT Plan 8 Holz', 47.0534394, 8.1699782],
    ['WT Plan 8 Buchenweid', 47.0528949, 8.1752562],
    ['WT Plan 8 Buchen', 47.0518094, 8.1770801],
    ['WT Plan 8 Fohren 1, 2', 47.0501058, 8.1788221],
    ['WT Plan 8 Grosshalden', 47.0482003, 8.173495],
    ['WT Plan 8 Buchenhalde', 47.0481418, 8.1702656],
    ['WT Plan 8 Buchenhalde 2', 47.0472301, 8.1664987],
    ['WT Plan 9 Eihalden', 47.0473837, 8.1767933],
    ['WT Plan 9 Ammehrgerige (1)', 47.0457827, 8.1680601],
    ['WT Plan 9 Ammehrgerige (2)', 47.0454386, 8.1692299],
    ['WT Plan 9 Brunhalde', 47.0460181, 8.1741004],
    ['WT Plan 9 Urnishalde 3 (1)', 47.0452486, 8.1755542],
    ['WT Plan 9 Urnishalde 3 (2)', 47.0450219, 8.1765627],
    ['WT Plan 9 Sonnenrain 10', 47.0456032, 8.1787582],
    ['WT Plan 9 Schwyzerhöfli Jagthütte', 47.0475946, 8.1807149],
    ['WT Plan 9 Schwyzerhöfli', 47.0479095, 8.1822888],
    ['WT Plan 10 Stollen', 47.0506825, 8.1849632],
    ['WT Plan 10 Oberwilgis', 47.0543765, 8.1786412],
    ['WT Plan 10 Unterwilgis', 47.053612, 8.1809796],
    ['WT Plan 10 Obertannhüsern', 47.0557802, 8.1786313],
    ['WT Plan 10 Tannhüsern', 47.0570584, 8.1812818],
    ['WT Plan 10 Wilgis', 47.0548703, 8.1838333],
    ['WT Plan 10 Oberknebligen', 47.0547242, 8.1876698],
    ['WT Plan 10 Unterknebligen', 47.0513849, 8.1899973],
    ['WT Plan 13 Unter-Oberzinggen', 47.0583832, 8.1954262],
    ['WT Plan 12 Lehn', 47.0494713, 8.1926228],
    ['WT Plan 12 Ei', 47.0471283, 8.1964772],
    ['WT Plan 12 Unterei', 47.0473148, 8.1987919],
    ['WT Plan 13 Margel', 47.0606375, 8.2017497],
    ['WT Plan 13 Margelhof', 47.0590076, 8.2031981],
    ['WT Plan 13 Neulimbach', 47.0637253, 8.2028923],
    ['WT Plan 13 Grindel', 47.0571837, 8.2009109],
    ['WT Plan 14 Kelsigen', 47.0566097, 8.2047984],
    ['WT Plan 14 Sonnhalden', 47.056617, 8.2091221],
    ['WT Plan 14 Untermoos', 47.0545045, 8.208017],
    ['WT Plan 14 Moos 3', 47.0546946, 8.2049056],
    ['WT Plan 14 Moos 2, 3', 47.0537005, 8.2020732],
    ['WT Plan 14 Schlatthof', 47.0528525, 8.1977008],
    ['WT Plan 14 Knüsligen', 47.0512901, 8.1982863],
    ['WT Plan 16 Witenthor', 47.0488044, 8.2036327],
    ['WT Plan 16 Brunauerhof', 47.0478321, 8.2088255],
    ['WT Plan 17 Buggenringen 1', 47.0580885, 8.216458],
    ['WT Plan 17 Buggenringen 2, 3', 47.0603387, 8.2172125],
    ['WT Plan 17 Spitzhof', 47.0640044, 8.220587],
    ['WT Plan 17, 18 Spitzhof a, b', 47.0652069, 8.224039],
    ['WT Plan 17, 18 Spitzmatt', 47.0659025, 8.2258187],
    ['WT Plan 17, 18 Spitzfluh', 47.0662459, 8.2266878],
    ['WT Plan 17, 18 Spitzfluhhof', 47.0662606, 8.2278733],
    ['WT Plan 17, 18 Spitzfluh Keller', 47.0664688, 8.228914],
    ['WT Plan 17, 18 Hirschpark', 47.0666397, 8.2272002],
    ['WT Plan 19 Brunau 3 Käserei', 47.0462705, 8.2185582],
    ['WT Plan 19 Neuhaushof', 47.0478288, 8.2180686],
    ['WT Plan 19', 47.0515212, 8.2221892],
    ['WT Plan 19 Brunau 2 Schulhaus', 47.0469742, 8.2196963],
    ['WT Plan 19 Brunau 1', 47.0472885, 8.2207692],
    ['WT Plan 19 Schürhof', 47.0482608, 8.2225448],
    ['WT Plan 19 Kaiserhof 3', 47.0485533, 8.223505],
    ['WT Plan 19 Kaiserhof 2, 3', 47.0483468, 8.2255676],
    ['WT Plan 20 Gansenbach', 47.0533162, 8.2278403],
    ['WT Plan 20 Fluck 1, 2', 47.0548201, 8.2347202],
    ['WT Plan 20 Kleinrüti', 47.0576671, 8.2374346],
    ['WT Plan 20 Rütihof', 47.0583708, 8.234274],
    ['WT Plan 20 Kornellen', 47.060695, 8.2329007],
    ['WT Plan 21 Gansenbach', 47.0515386, 8.2315266],
    ['WT Plan 21 Fluckrain', 47.0520258, 8.2335914],
    ['WT Plan 21 Spahau 1', 47.0492213, 8.2380698],
    ['WT Plan 21 Spahau 2', 47.0481248, 8.2373295],
    ['WT Plan 22 Grundligen', 47.0610815, 8.2460475],
    ['WT Plan 22 Krattenbach', 47.0599578, 8.2402968],
    ['WT Plan 22 Sidlern', 47.0568054, 8.2404417],
    ['WT Plan 22 Kollerhüsli', 47.0571051, 8.2437462],
    ['WT Plan 22 Baumgarten', 47.0573755, 8.2467395],
    ['WT Plan 23 Neubühl', 47.0689253, 8.2286373],
    ['WT Plan 23 Mooshof', 47.064788, 8.2341165],
    ['WT Plan 23 Hueb', 47.0662642, 8.2382471],
    ['WT Plan 30 Dünnhirs', 47.0366772, 8.114372],
    ['WT Plan 30 Lochgut', 47.038125, 8.115917],
    ['WT Plan 30 Lochmühle', 47.0348053, 8.1159813],
    ['WT Plan 30 Rothenfluh', 47.0368088, 8.1194146],
    ['WT Plan 30 Rothenfluh 3, 5', 47.0370006, 8.1240666],
    ['WT Plan 30 Rothenfluh 1', 47.0331367, 8.1246382],
    ['WT Plan 30 Rothenfluh 2', 47.0352061, 8.1255072],
    ['WT Plan 30 Rothenfluh 4, 6', 47.0359519, 8.1274384],
    ['WT Plan 33 Ober Neumatt', 47.0148406, 8.1218626],
    ['WT Plan 33 Unter Neumatt', 47.0167425, 8.1217124],
    ['WT Plan 31 Unter Rohr', 47.0120173, 8.1239195],
    ['WT Plan 31 Ober Rohr', 47.0116528, 8.1190029],
    ['WT Plan 31 Rohr', 47.0096447, 8.1183967],
    ['WT Plan 31 Rohrmösli', 47.0087229, 8.1166157],
    ['WT Plan 31 Blattegghüsli', 47.0067681, 8.120083],
    ['WT Plan 32 Stöckere', 47.0296074, 8.1190853],
    ['WT Plan 32 Eggischwand', 47.024173, 8.1206527],
    ['WT Plan 32 Branderhüsli', 47.0200021, 8.1183967],
    ['WT Plan 32 Engi', 47.0212333, 8.1223005],
    ['WT Plan 32 Holtzplatz', 47.0216797, 8.125576],
    ['WT Plan 35 Fahrnbühlbad', 47.0247715, 8.1277313],
    ['WT Plan 33 Hüttenhof', 47.0178467, 8.121056],
    ['WT Plan 33 Fischenbach Neuhus', 47.017635, 8.1255875],
    ['WT Plan 34 Langnau 7', 47.0443296, 8.1203586],
    ['WT Plan 34 Dütschenberg', 47.0421095, 8.1212449],
    ['WT Plan 34 Langnau 1-5', 47.0424577, 8.1265127],
    ['WT Plan 34 Langnau', 47.0418143, 8.1291199],
    ['WT Plan 35 Ober Farnbühl', 47.0220636, 8.1298614],
    ['WT Plan 35 Renggstr. 19', 47.0253109, 8.1301189],
    ['WT Plan 35 Renggstr. 20', 47.025757, 8.1298614],
    ['WT Plan 35 Erlen', 47.0266851, 8.1297286],
    ['WT Plan 39 Unter Fahrnbühl', 47.02334, 8.1349253],
    ['WT Plan 39 Renggstr. 17', 47.0238447, 8.1326723],
    ['WT Plan 39 Mülihalde', 47.0222612, 8.1360143],
    ['WT Plan 39 Würgisweid', 47.0238995, 8.1368512],
    ['WT Plan 35 Renggstr. 15', 47.0251282, 8.1331283],
    ['WT Plan Renggstr. 11, 13', 47.0261023, 8.1342655],
    ['WT Plan 39 Paradiesli', 47.0276503, 8.1317282],
    ['WT Plan 35 Renggstr. 9', 47.02673, 8.1351615],
    ['WT Plan 35 Fischbachstr. 7', 47.0199485, 8.1347055],
    ['WT Plan 35 Fischbachstr. 10', 47.0193707, 8.1312508],
    ['WT Plan 35 Fischbachstr. 9, 12', 47.0179736, 8.1303818],
    ['WT Plan 35 Fischenbachweidli', 47.0181126, 8.1345338],
    ['WT Plan 38 Eggweid', 47.0172719, 8.1368683],
    ['WT Plan 36 Möslistr. 10', 47.0380911, 8.1300889],
    ['WT Plan 36 Möslistr. 8, 3', 47.0360219, 8.1349705],
    ['WT Plan 36 Möslistr. (1)', 47.035181, 8.1366013],
    ['WT Plan 36 Möslistr. (2)', 47.0338574, 8.1366979],
    ['WT Plan 36 Möslistr. (3)', 47.0330823, 8.1388544],
    ['WT Plan 36 Bühlm', 47.0309689, 8.1399272],
    ['WT Plan 36 Aerdbrüststr. (1)', 47.0319489, 8.1377493],
    ['WT Plan 36 Altgade', 47.0320431, 8.1350014],
    ['WT Plan 36 Aerdbrüststr. (2)', 47.0323356, 8.1329737],
    ['WT Plan 36 Aerdbrüsthüseli', 47.0355896, 8.1331024],
    ['WT Plan 36 Farnbühlweid', 47.030014, 8.1329459],
    ['WT Plan 36 Renggstr. 12', 47.0302846, 8.1389219],
    ['WT Plan 40 Renggstr. 10', 47.0320689, 8.1426019],
    ['WT Plan 38 Fischenbachstr. 8', 47.0213544, 8.1347959],
    ['WT Plan 38 Eggweidli', 47.0184847, 8.1376374],
    ['WT Plan 38 Kanternweid', 47.0155716, 8.1350303],
    ['WT Plan 38 Munistein', 47.0141517, 8.1318124],
    ['WT Plan 38 Mühlebachweid', 47.0156741, 8.1394302],
    ['WT Plan 39 Renggstr. 7', 47.0286676, 8.1365132],
    ['WT Plan 39 Fischbachstr. 3, 5', 47.0242935, 8.1389228],
    ['WT Plan 39 Fischbachstr. 4, 6', 47.0275179, 8.1410909],
    ['WT Plan 40 Fischbachstr. 2', 47.0300632, 8.1418857],
    ['WT Plan 40 Fischbachstr. 1', 47.0308896, 8.1436667],
    ['WT Plan 40 Renggstr. 8', 47.0337702, 8.142675],
    ['WT Plan 40 Renggstr. 3', 47.0320591, 8.1444024],
    ['WT Plan 40 Renggstr. 6', 47.0342229, 8.143892],
    ['WT Plan 40 Renggstr. 1', 47.0350711, 8.1450507],
    ['WT Plan 40 Ober Zihl', 47.0351369, 8.149192],
    ['WT Plan 40 Islern', 47.0330163, 8.1485805],
    ['WT Plan 41 Egg (1)', 47.0232694, 8.1500084],
    ['WT Plan 41 Egg (2)', 47.0235534, 8.1479287],
    ['WT Plan 41 Kantern', 47.023696, 8.1467164],
    ['WT Plan 41 Ferienhaus', 47.0232242, 8.1445491],
    ['WT Plan 41 Mühlacher', 47.0223978, 8.1427252],
    ['WT Plan 41 Hinterschwand 2', 47.0205108, 8.1431115],
    ['WT Plan 41 Hinterschwand 1, 3', 47.0200353, 8.1440954],
    ['WT Plan 41 Mittlerschwand', 47.020262, 8.1456511],
    ['WT Plan 41 Vorderschwand', 47.020299, 8.1477551],
    ['WT Plan 44 Eggstücki', 47.0203722, 8.1490586],
    ['WT Plan 42 Holzgut', 47.0283621, 8.1461897],
    ['WT Plan 42 Staub', 47.0282012, 8.1492152],
    ['WT Plan 42 Allmend 6', 47.0295103, 8.1527665],
    ['WT Plan 42 Allmend 5', 47.0295322, 8.1537964],
    ['WT Plan 42 Allmend 7', 47.0274699, 8.1525733],
    ['WT Plan 42 Allmend 8', 47.0257875, 8.1535911],
    ['WT Plan 45 Allmend 13, 14', 47.0285401, 8.1546962],
    ['WT Plan 45 Allmend 12', 47.0285254, 8.1578934],
    ['WT Plan 43 Obergrabacher', 47.0325367, 8.1513606],
    ['WT Plan 43 Moosmättli', 47.0323733, 8.1550174],
    ['WT Plan 42 Allmend 4', 47.0309828, 8.1558042],
    ['WT Plan 43 Allmend 2, 3', 47.0312571, 8.1583684],
    ['WT Plan 43 Unter Grabacher', 47.0337398, 8.1541517],
    ['WT Plan 43 Grabenmatt 3', 47.0330086, 8.1542429],
    ['WT Plan 43 Luegisland', 47.0331622, 8.1560561],
    ['WT Plan 45 Allmend 10', 47.0293051, 8.1586911],
    ['WT Plan 43 Grabenmatt 2', 47.0341418, 8.1549119],
    ['WT Plan 43 Hofmatt', 47.0351362, 8.1573298],
    ['WT Plan 44 Tannacher', 47.0214304, 8.1537897],
    ['WT Plan 44 Mühlebach', 47.0217904, 8.1597528],
    ['WT Plan 44 Breitenacher', 47.0239333, 8.1590125],
    ['WT Plan 44 Allmend 15, 16', 47.0254912, 8.1592235],
    ['WT Plan 47 Hüseliweid', 47.0215125, 8.1620667],
    ['WT Plan 45 Hüseli 1, 2', 47.0244506, 8.1635121],
    ['WT Plan 45 Mattguthüsli', 47.0297513, 8.163468],
    ['WT Plan 45 Weidhüsli', 47.0288591, 8.1602386],
    ['WT Plan 45 Mattgutweid', 47.0273672, 8.1609145],
    ['WT Plan 45 Mattgut', 47.0266505, 8.1635753],
    ['WT Plan 45 Unter Mattgut', 47.0276378, 8.1643692],
    ['WT Plan 46 Fridligen', 47.0323317, 8.1670687],
    ['WT Plan 47 Liebetsegg', 47.0245065, 8.1674412],
    ['WT Plan 47 Liebetsegg 4', 47.0244772, 8.1701216],
    ['WT Plan 47 Liebetseggweid', 47.0261593, 8.1702288],
    ['WT Plan 47 Schlatt', 47.0213516, 8.1683887],
    ['WT Plan 47 Boden', 47.0226901, 8.1722134],
    ['WT Plan 47 Bodenhalden', 47.0240139, 8.1734579],
    ['WT Plan 48 Schürmatt', 47.0282615, 8.1674679],
    ['WT Plan 48 Gimmermehr', 47.027362, 8.1701072],
    ['WT Plan 48 Kellen', 47.0285102, 8.1725856],
    ['WT Plan 48 Rotherdweid', 47.0273656, 8.1751176],
    ['WT Plan 48 Kellenmatt', 47.0293341, 8.174805],
    ['WT Plan 48 Schürmattweid', 47.0303579, 8.1719941],
    ['WT Plan 48 Hübeli', 47.0309502, 8.1745475],
    ['WT Plan 48 Hinterwydenmatt', 47.032347, 8.1734424],
    ['WT Plan 48 Ober Luegeten', 47.0304647, 8.1807806],
    ['WT Plan 51 Wipfern 2', 47.0319754, 8.1833761],
    ['WT Plan 51 Wipfern', 47.0301399, 8.1837624],
    ['WT Plan 53 Hofhöhe (1)', 47.0306855, 8.1957236],
    ['WT Plan 53 Untersiten', 47.0291828, 8.1906758],
    ['WT Plan 51 Hofweid', 47.0328407, 8.1967977],
    ['WT Plan 51 Hof', 47.0344934, 8.1958167],
    ['WT Plan 51 Rütiwegen', 47.034157, 8.1922976],
    ['WT Plan 51 Bühl 6', 47.0327237, 8.1917719],
    ['WT Plan 51 Bühl (1)', 47.0332356, 8.1904737],
    ['WT Plan 51 Bühl (2)', 47.033572, 8.1880061],
    ['WT Plan 51 Bühl (3)', 47.0324312, 8.1888537],
    ['WT Plan 51 Hofhalden', 47.0370747, 8.1941872],
    ['WT Plan 52 Bühlacher (1)', 47.0382499, 8.1924662],
    ['WT Plan 52 Stöckern', 47.0389519, 8.1977984],
    ['WT Plan 52 Bühlacher (2)', 47.0394198, 8.1958511],
    ['WT Plan 53 Obersiten', 47.0282793, 8.1923356],
    ['WT Plan 53 Hofhöhe (2)', 47.0302194, 8.1985311],
    ['WT Plan 53 Gspan', 47.0306037, 8.2082882],
    ['WT Plan 54 Unterhasenholz', 47.0407367, 8.1997836],
    ['WT Plan 54 Hasenholz', 47.03998, 8.1997246],
    ['WT Plan 54 Unterrothen', 47.0384921, 8.202954],
    ['WT Plan 54 Helmern', 47.0402615, 8.2035334],
    ['WT Plan 54 Hinterrothen', 47.0380095, 8.2063551],
    ['WT Plan 54 Oberrothen', 47.0376146, 8.2042951],
    ['WT Plan 54 Kaiserstuhl', 47.0376585, 8.2005078],
    ['WT Plan 54 Fuchsmättli 1', 47.0365544, 8.1998963],
    ['WT Plan 54 Fuchsmätli 2', 47.0345519, 8.1995727],
    ['WT Plan 56 Stierenweid 1', 47.0394339, 8.2186007],
    ['WT Plan 56 Stierenweid 2', 47.0383106, 8.2187368],
    ['WT Plan 56 Egerten 2', 47.0382521, 8.2125824],
    ['WT Plan 56 Egerten 1', 47.0383984, 8.2152861],
    ['WT Plan 56 Graben', 47.0377184, 8.2101148],
    ['WT Plan 56 Untergraben', 47.0397949, 8.21078],
    ['WT Plan 56 Stegmättli', 47.0410337, 8.2095975],
    ['WT Plan 56 Sonnmättli', 47.0411361, 8.2116789],
    ['WT Plan 56 Egertenstücke', 47.0417868, 8.2177514],
    ['WT Plan 56 Stegmättli 12', 47.0420134, 8.2153589],
    ['WT Plan 58 Wärterhaus', 47.0433439, 8.2232687],
    ['WT Plan 58 (1)', 47.0395944, 8.2251497],
    ['WT Plan 58 (2)', 47.040969, 8.225729],
    ['WT Plan 58 Untersentimatt', 47.0412148, 8.2296644],
    ['WT Plan 58 (3)', 47.0384486, 8.2290567],
    ['WT Plan 59 Dobermannclub', 47.0454341, 8.237877],
    ['WT Plan 59 Unterrengg', 47.0412455, 8.236151],
    ['WT Plan 59 Oberrengg (1)', 47.0400976, 8.2364407],
    ['WT Plan 59 Oberrengg (2)', 47.0402511, 8.2393911],
    ['WT Plan 59 Lötscher', 47.0418596, 8.2380929],
];

// ─── Import ausführen ──────────────────────────────────────────────────────
$db = getDb();

// Prüfen ob Feuerwehr existiert
$check = $db->prepare('SELECT name FROM fire_departments WHERE id = ?');
$check->execute([$deptId]);
$dept = $check->fetch();
if (!$dept) {
    die('<p style="font:16px sans-serif;color:red">Fehler: Keine Feuerwehr mit ID ' . $deptId . ' gefunden.</p>');
}

$stmt = $db->prepare(
    'INSERT INTO wasser_transport_plans (department_id, name, lat, lng)
     VALUES (?, ?, ?, ?)'
);

$count = 0;
$errors = [];
foreach ($placemarks as $p) {
    try {
        $stmt->execute([$deptId, $p[0], $p[1], $p[2]]);
        $count++;
    } catch (Exception $e) {
        $errors[] = htmlspecialchars($p[0]) . ': ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8" />
    <title>KML Import</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #0f0f14;
            color: #f1f1f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .card {
            background: #18181f;
            border-radius: 16px;
            padding: 28px 24px;
            max-width: 480px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, .08);
        }

        h1 {
            font-size: 18px;
            margin-bottom: 16px;
        }

        .ok {
            color: #4ade80;
        }

        .err {
            color: #e63946;
            font-size: 13px;
            margin-top: 8px;
        }

        .warn {
            background: rgba(244, 162, 97, .1);
            border: 1px solid #f4a261;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 16px;
            font-size: 13px;
            color: #f4a261;
        }

        a {
            color: #38bdf8;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>💧 KML Import – WasserTransportPläne</h1>
        <p class="ok">✅
            <?= $count ?> Einträge erfolgreich importiert für «
            <?= htmlspecialchars($dept['name']) ?>».
        </p>
        <?php if ($errors): ?>
            <?php foreach ($errors as $e): ?>
                <p class="err">⚠️
                    <?= $e ?>
                </p>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="warn">
            ⚠️ <strong>Datei jetzt löschen!</strong><br>
            Diese Seite muss vom Server entfernt werden:<br>
            <code>import_wtp_kml.php</code>
        </div>
        <p style="margin-top:16px">
            <a href="/admin/wtp.html">→ Zu den WasserTransportPlänen</a>
        </p>
    </div>
</body>

</html>