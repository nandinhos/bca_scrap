-- ===========================================
-- Schema e Dados Iniciais - Sistema BCA
-- GAC-PAC
-- ===========================================

-- Tabela de efetivo do GAC-PAC
CREATE TABLE IF NOT EXISTS efetivo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    saram VARCHAR(8) NOT NULL UNIQUE,
    nome_guerra VARCHAR(50) NOT NULL,
    nome_completo VARCHAR(200) NOT NULL,
    posto VARCHAR(20) NOT NULL,
    especialidade VARCHAR(50),
    email VARCHAR(255),
    om_origem VARCHAR(50) DEFAULT 'GAC-PAC',
    ativo TINYINT(1) DEFAULT 1,
    oculto TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_saram (saram),
    INDEX idx_ativo (ativo)
);

-- Tabela de emails enviados
CREATE TABLE IF NOT EXISTS bca_email (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    func_id INT NOT NULL,
    texto TEXT,
    bca VARCHAR(255),
    data DATE,
    enviado TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (func_id) REFERENCES efetivo(id)
);

-- Tabela de palavras-chave específicas do GAC-PAC
CREATE TABLE IF NOT EXISTS palavras_chave (
    id INT AUTO_INCREMENT PRIMARY KEY,
    palavra VARCHAR(100) NOT NULL,
    cor VARCHAR(6) DEFAULT 'FFFFFF',
    ativa TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inserir palavras-chave do GAC-PAC
INSERT INTO palavras_chave (palavra, cor, ativa) VALUES 
('GAC-PAC', '3498DB', 0),
('COPAC', '2d54f0', 0),
('LINK-BR', 'db2424', 0),
('KC-390', '24db42', 0),
('KC-X', '48d560', 0),
('FX-2', 'd3d548', 0),
('CAS', '48abd5', 0),
('CAA', '48abd5', 0),
('CEAG', '48abd5', 0);

-- Tabela de log de execuções
CREATE TABLE IF NOT EXISTS bca_execucoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(20) NOT NULL,
    data_execucao DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL,
    mensagem TEXT,
    registros_processados INT DEFAULT 0,
    INDEX idx_tipo (tipo),
    INDEX idx_data (data_execucao)
);

-- --------------------------------------------------------
-- Dados: Efetivo GAC-PAC
-- --------------------------------------------------------
INSERT INTO efetivo (saram, nome_guerra, nome_completo, posto, especialidade, email, om_origem, ativo, oculto) VALUES
('3047512', 'CASTILHO', 'Diogo Silva CASTILHO', 'Cel Av', NULL, 'castilhodsc@fab.mil.br', 'GAC-PAC', 1, 0),
('1351370', 'PRATESI', 'Antonio Luis Kostienkow PRATESI', 'Cel Av R/1', NULL, 'pratesialkp@fab.mil.br', 'GAC-PAC', 1, 0),
('3257916', 'FONTES', 'Pablo Rodrigues FONTES', 'Ten Cel Av', NULL, 'fontesprf@fab.mil.br', 'GAC-PAC', 1, 0),
('3324052', 'VALNECK', 'VALNECK Peixoto de Oliveira Melo', 'Ten Cel Av', NULL, 'valneckvpom@fab.mil.br', 'GAC-PAC', 1, 0),
('3686515', 'MATTOS BRITO', 'FRANCISCO DE MATTOS BRITO JUNIOR', 'TEN CEL ENG', NULL, 'mattosbritofmbj@fab.mil.br', 'GAC-PAC', 1, 0),
('3490351', 'CAPUCHINHO', 'Thiago Romeiro CAPUCHINHO', 'Maj Av', NULL, 'capuchinhotrc@fab.mil.br', 'GAC-PAC', 1, 0),
('4111281', 'LACERDA', 'Renan de LACERDA Lima Gonçalves', 'Maj Int', NULL, 'lacerdarllg@fab.mil.br', 'GAC-PAC', 1, 0),
('6084966', 'THAIANE BENETTI', 'THAIANE BENETTI CARVALHO DE OLIVEIRA VIEIRA', 'CAP INT', NULL, 'thaianebenettitbcov@fab.mil.br', 'GAC-PAC', 1, 0),
('6123120', 'MACÊDO', 'Rafael MACÊDO Trindade', 'Cap Eng', NULL, 'macedormt@fab.mil.br', 'GAC-PAC', 1, 0),
('6425216', 'HELANE', 'HELANE Rosario da Cruz Nogueira', 'Cap Int', NULL, 'helanehrcn@fab.mil.br', 'GAC-PAC', 1, 0),
('1673327', 'NELSON', 'NELSON Rodrigues da Costa Filho', 'Cap QOEA ANV R/1', NULL, 'nelsonnrcf@fab.mil.br', 'GAC-PAC', 1, 0),
('1645439', 'SANTI', 'Leandro SANTI da Silva', 'Cap QOEA SVA R/1', NULL, 'santilss@fab.mil.br', 'GAC-PAC', 1, 0),
('1985736', 'MICHETTI', 'Marcos Roberto MICHETTI', 'Cap QOEA ANV R/1', NULL, 'michettimrm@fab.mil.br', 'GAC-PAC', 1, 0),
('2603624', 'OLIVEIRA', 'Robson de OLIVEIRA Parada', 'Cap QOEA SUP R/1', NULL, 'oliveirarop@fab.mil.br', 'GAC-PAC', 1, 0),
('3448703', 'MELO', 'Thiago de MELO Rocha', '1° Ten QOEA ANV', NULL, 'melotmr@fab.mil.br', 'GAC-PAC', 1, 0),
('7391110', 'CATIANA FARIA', 'CATIANA FARIA DOS SANTOS', '1° TEN QOCON ADM', NULL, 'catianacfs@fab.mil.br', 'GAC-PAC', 1, 0),
('7391188', 'MILITÃO', 'ANGELA de Lima MILITÃO', '1° Ten QOCon ADM', NULL, 'angelamilitaoalm@fab.mil.br', 'GAC-PAC', 1, 0),
('7433794', 'TATIANA ROCHA', 'TATIANA SOUSA DA ROCHA', '1° TEN QOCON CCO', NULL, 'tatianarochatsr@fab.mil.br', 'GAC-PAC', 1, 0),
('7432445', 'MARIANA RODRIGUES', 'MARIANA RODRIGUES QUEIROZ MOREIRA', '1° TEN QOCON CCO', NULL, 'mariana.rodrigues@gmail.com', 'GAC-PAC', 1, 0),
('3245926', 'FRANCO', 'Gustavo Luiz FRANCO', '1° Ten Esp Aer SUP', NULL, 'francoglf@fab.mil.br', 'GAC-PAC', 1, 0),
('7534710', 'PRADO', 'Matheus PRADO', '2° Ten QOCon PRU', NULL, 'pradomp@fab.mil.br', 'GAC-PAC', 1, 0),
('7537301', 'ANA PRIANTE', 'ANA CLÁUDIA APARECIDA PRIANTE', '2° TEN QOCON CCO', NULL, 'anaprianteacap@fab.mil.br', 'GAC-PAC', 1, 0),
('7623070', 'CARLA', 'CARLA Pereira Machado Homem', '2° Ten QOCon ADM', NULL, 'carlacpmh@fab.mil.br', 'GAC-PAC', 1, 0),
('2714710', 'PROENÇA', 'Rogério da Silva PROENÇA', '2° Ten Esp Aer SUP', NULL, 'proencarsp@fab.mil.br', 'GAC-PAC', 1, 0),
('3503186', 'ALEX SANDRO', 'ALEX SANDRO SOUTO BARBOSA', '2° TEN ESP AER ANV', NULL, 'alexsandroassb@fab.mil.br', 'GAC-PAC', 1, 0),
('2086735', 'MARTINO', 'Flávio de Souza MARTINO', 'SO BMA', NULL, 'martinofsm@fab.mil.br', 'GAC-PAC', 1, 0),
('2345560', 'LOBO', 'Marcos Antonio Muniz LOBO', 'SO BMA', NULL, 'lobomaml@fab.mil.br', 'GAC-PAC', 1, 0),
('3372332', 'SILVIA', 'SILVIA Soares Ferreira Gonçalves', 'SO SAD', NULL, 'silviassfg@fab.mil.br', 'GAC-PAC', 1, 0),
('2961849', 'CLEI', 'Gilson CLEI José Barreto', 'SO SAD', NULL, 'cleigcjb@fab.mil.br', 'GAC-PAC', 1, 0),
('3288536', 'MICHEL', 'MICHEL da Silva Soares', 'SO BMA', NULL, 'michelmss@fab.mil.br', 'GAC-PAC', 1, 0),
('2818477', 'ANDEILTON', 'ANDEILTON Gomes de Souza', 'SO BMA', NULL, 'andeiltonags@fab.mil.br', 'GAC-PAC', 1, 0),
('3381218', 'ESTRELA', 'Filipe ESTRELA Nunes', 'SO BET', NULL, 'estrelafen@fab.mil.br', 'GAC-PAC', 1, 0),
('2946521', 'CARLOS', 'João CARLOS da Silva Pinto', 'SO BMA', NULL, 'carlosjcsp@fab.mil.br', 'GAC-PAC', 1, 0),
('3455378', 'RONALD', 'BRUNO RONALD DA SILVA', 'SO SAD', NULL, 'ronaldbrs@fab.mil.br', 'GAC-PAC', 1, 0),
('4069323', 'DARIELE', 'DARIELE Elisa Reis Breginski', 'SO BET', NULL, 'darielederb@fab.mil.br', 'GAC-PAC', 1, 0),
('3210685', 'RUBIM', 'Anderson RUBIM Musi Dias', 'SO SAD', NULL, 'rubimarmd@fab.mil.br', 'GAC-PAC', 1, 0),
('4039769', 'QUINTELA', 'Raquel QUINTELA Gomes do Nascimento', 'SO SAD', NULL, 'quintelarqgn@fab.mil.br', 'GAC-PAC', 1, 0),
('3034968', 'BEMFICA', 'André da Silva BEMFICA', 'SO SAD', NULL, 'bemficaasb@fab.mil.br', 'GAC-PAC', 1, 0),
('0621714', 'JESUS', 'Hélio Marcos de JESUS', 'SO BSP Refm', NULL, 'jesushmj@fab.mil.br', 'GAC-PAC', 1, 0),
('2709988', 'MOISES', 'MOISES Ferreira da Silva', '1S BMA', NULL, 'moisesmfs@fab.mil.br', 'GAC-PAC', 1, 0),
('3341704', 'ADEMIR', 'ADEMIR Aparecido de Freitas', '1S BMB', NULL, 'ademiraaf@fab.mil.br', 'GAC-PAC', 1, 0),
('4279565', 'TREVISAN', 'Euclides Jorge TREVISAN Filho', '1S BMA', NULL, 'trevisanejtf@fab.mil.br', 'GAC-PAC', 1, 0),
('3463907', 'BRASIL', 'Vagner de Oliveira BRASIL', '1S BSP', NULL, 'brasilvob@fab.mil.br', 'GAC-PAC', 1, 0),
('4360389', 'GISELE SILVA', 'GISELE SILVA ODILON', '1S BSP', NULL, 'giselesilvagso@fab.mil.br', 'GAC-PAC', 1, 0),
('3467317', 'LIMA', 'Marcelo LIMA da Silva', '1S SAD', NULL, 'limamls@fab.mil.br', 'GAC-PAC', 1, 0),
('4112695', 'FERNANDO', 'FERNANDO dos Santos Souza', '1S BMB', NULL, 'fernandofss@fab.mil.br', 'GAC-PAC', 1, 0),
('6323847', 'BRUM', 'Eric Tiago Zuchi de Andrade BRUM', '2S BMA', NULL, 'brumetzab@fab.mil.br', 'GAC-PAC', 1, 0),
('4157940', 'AMADOR', 'Maicon Fonseca AMADOR', '2S SAD', NULL, 'amadormfa@fab.mil.br', 'GAC-PAC', 1, 0),
('6329969', 'DOLFINI', 'Gustavo Rosa DOLFINI', '2S BMA', NULL, 'dolfinigrd@fab.mil.br', 'GAC-PAC', 1, 0),
('6255620', 'ANDRESSA COSTA', 'ANDRESSA XAVIER DA COSTA', '2S SIN', NULL, 'andressacostaaxc@fab.mil.br', 'GAC-PAC', 1, 0);
