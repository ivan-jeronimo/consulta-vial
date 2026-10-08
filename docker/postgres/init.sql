-- Se ejecuta solo la primera vez que se crea el volumen de datos.
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- Base de datos aislada para PHPUnit (RefreshDatabase la vacía en cada corrida).
CREATE DATABASE consulta_vial_test;
\c consulta_vial_test
CREATE EXTENSION IF NOT EXISTS vector;
