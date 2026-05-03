-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Gép: 127.0.0.1
-- Létrehozás ideje: 2026. Már 11. 23:11
-- Kiszolgáló verziója: 10.4.32-MariaDB
-- PHP verzió: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Adatbázis: `fs_acces`
--

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `biralat`
--

CREATE TABLE `biralat` (
  `biralat_ID` int(255) NOT NULL,
  `kerelem_ID` int(255) NOT NULL,
  `admin_ID` int(255) NOT NULL,
  `rendszer` varchar(255) NOT NULL,
  `dontes` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `ceg`
--

CREATE TABLE `ceg` (
  `ceg_ID` int(255) NOT NULL,
  `ceg_nev` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `igenylo`
--

CREATE TABLE `igenylo` (
  `igenylo_ID` int(255) NOT NULL,
  `igenylo_nev` varchar(255) NOT NULL,
  `terulet_ID` varchar(255) NOT NULL,
  `igenylo_email` varchar(255) NOT NULL,
  `igenylo_password` varchar(255) NOT NULL,
  `igenylo_jog` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- A tábla adatainak kiíratása `igenylo`
--

INSERT INTO `igenylo` (`igenylo_ID`, `igenylo_nev`, `terulet_ID`, `igenylo_email`, `igenylo_password`, `igenylo_jog`) VALUES
(1, 'evelinhrvt', '', 'evo.horvat@gmail.com', '$2y$10$o39pbreEmssruHqRxwqXcuY/HuoIwuw.vsgus6uatL5bIl2qQUzeC', 'it_admin'),
(5, 'Demo_user', '', 'demo.user@gmail.com', '$2y$10$pGgU0hxmg5/5hVUMG8Aqau/s5OjHvX.HI/L49IkrwJn2Ei7MVbc5a', 'user'),
(6, 'Demo_mappafelelos', '', 'demo.mappafelelos@gmail.com', '$2y$10$3xPdTNaiJMvy7NR0bsf3ZePIL4SpaK27NSJogt83rT3cBGAFWnmIm', 'mappa_felelos'),
(7, 'Demo_masodlagos_felelos', '', 'demo.masodlagos.felelos@gmail.com', '$2y$10$jQV7JkZ0c5BSoulDlVZ3GO3HPhfk6wpSKDflKzwJ9Fkg09OmBow..', 'masodlagos_felelos');

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `igenylok_do`
--

CREATE TABLE `igenylok_do` (
  `igenylo_ID` int(11) NOT NULL,
  `igenylo_nev` varchar(255) NOT NULL,
  `igenylo_email` varchar(255) DEFAULT NULL,
  `terulet_ID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `kerelem`
--

CREATE TABLE `kerelem` (
  `kerelem` int(255) NOT NULL,
  `kerelem_datum` date NOT NULL,
  `igenylo_id` int(255) NOT NULL,
  `indoklas` varchar(255) NOT NULL,
  `megosztas_ID` int(255) NOT NULL,
  `hozzaferes_tipusa` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `admin_comment` text DEFAULT NULL,
  `elfogado_ID` int(255) DEFAULT NULL,
  `leader_notified` tinyint(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `kerelem_do`
--

CREATE TABLE `kerelem_do` (
  `kerelem_ID` int(11) NOT NULL,
  `igenylo_ID` int(11) DEFAULT NULL,
  `megosztas_ID` int(11) DEFAULT NULL,
  `indoklas` text DEFAULT NULL,
  `hozzaferes_tipusa` varchar(50) DEFAULT NULL,
  `kerelem_datum` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `leader_notified` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `megosztasok`
--

CREATE TABLE `megosztasok` (
  `megosztas_ID` int(255) NOT NULL,
  `megosztas_neve` varchar(255) NOT NULL,
  `terulet_ID` int(255) NOT NULL,
  `felelos_ID` int(255) NOT NULL,
  `masodlagos_felelos` int(255) NOT NULL,
  `utolso_ellenorzes_datum` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- A tábla adatainak kiíratása `megosztasok`
--

INSERT INTO `megosztasok` (`megosztas_ID`, `megosztas_neve`, `terulet_ID`, `felelos_ID`, `masodlagos_felelos`, `utolso_ellenorzes_datum`) VALUES
(1, 'Demo1_RO', 1, 1, 2, '2020-10-14'),
(2, 'Demo2_RW', 1, 1, 2, '2020-10-14');

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `megosztasok_do`
--

CREATE TABLE `megosztasok_do` (
  `megosztas_ID` int(11) NOT NULL,
  `megosztas_neve` varchar(255) NOT NULL,
  `terulet_ID` int(11) DEFAULT NULL,
  `felelos_ID` int(11) DEFAULT NULL,
  `masodlagos_felelos_ID` int(11) DEFAULT NULL,
  `utolso_ellenorzes_datum` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `terulet`
--

CREATE TABLE `terulet` (
  `terulet_ID` int(11) NOT NULL,
  `terulet_nev` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tábla szerkezet ehhez a táblához `terulet_do`
--

CREATE TABLE `terulet_do` (
  `terulet_ID` int(11) NOT NULL,
  `terulet_nev` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexek a kiírt táblákhoz
--

--
-- A tábla indexei `biralat`
--
ALTER TABLE `biralat`
  ADD PRIMARY KEY (`biralat_ID`);

--
-- A tábla indexei `ceg`
--
ALTER TABLE `ceg`
  ADD PRIMARY KEY (`ceg_ID`);

--
-- A tábla indexei `igenylo`
--
ALTER TABLE `igenylo`
  ADD PRIMARY KEY (`igenylo_ID`);

--
-- A tábla indexei `igenylok_do`
--
ALTER TABLE `igenylok_do`
  ADD PRIMARY KEY (`igenylo_ID`);

--
-- A tábla indexei `kerelem`
--
ALTER TABLE `kerelem`
  ADD PRIMARY KEY (`kerelem`);

--
-- A tábla indexei `kerelem_do`
--
ALTER TABLE `kerelem_do`
  ADD PRIMARY KEY (`kerelem_ID`);

--
-- A tábla indexei `megosztasok`
--
ALTER TABLE `megosztasok`
  ADD PRIMARY KEY (`megosztas_ID`);

--
-- A tábla indexei `megosztasok_do`
--
ALTER TABLE `megosztasok_do`
  ADD PRIMARY KEY (`megosztas_ID`);

--
-- A tábla indexei `terulet`
--
ALTER TABLE `terulet`
  ADD PRIMARY KEY (`terulet_ID`);

--
-- A tábla indexei `terulet_do`
--
ALTER TABLE `terulet_do`
  ADD PRIMARY KEY (`terulet_ID`);

--
-- A kiírt táblák AUTO_INCREMENT értéke
--

--
-- AUTO_INCREMENT a táblához `biralat`
--
ALTER TABLE `biralat`
  MODIFY `biralat_ID` int(255) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `ceg`
--
ALTER TABLE `ceg`
  MODIFY `ceg_ID` int(255) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `igenylo`
--
ALTER TABLE `igenylo`
  MODIFY `igenylo_ID` int(255) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT a táblához `igenylok_do`
--
ALTER TABLE `igenylok_do`
  MODIFY `igenylo_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `kerelem`
--
ALTER TABLE `kerelem`
  MODIFY `kerelem` int(255) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `kerelem_do`
--
ALTER TABLE `kerelem_do`
  MODIFY `kerelem_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `megosztasok`
--
ALTER TABLE `megosztasok`
  MODIFY `megosztas_ID` int(255) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT a táblához `megosztasok_do`
--
ALTER TABLE `megosztasok_do`
  MODIFY `megosztas_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `terulet`
--
ALTER TABLE `terulet`
  MODIFY `terulet_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT a táblához `terulet_do`
--
ALTER TABLE `terulet_do`
  MODIFY `terulet_ID` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
