-- Tabela de efetivo do GAC-PAC/COPAC
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

-- Tabela de palavras-chave específicas do GAC-PAC/COPAC
CREATE TABLE IF NOT EXISTS palavras_chave (
    id INT AUTO_INCREMENT PRIMARY KEY,
    palavra VARCHAR(100) NOT NULL,
    cor VARCHAR(6) DEFAULT 'FFFFFF',
    ativa TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inserir palavras-chave do GAC-PAC/COPAC
INSERT INTO palavras_chave (palavra, cor) VALUES 
('GAC-PAC', '3498DB'),
('COPAC', 'E74C3C'),
('GRUPO DE AVIAÇÃO CIVIL', '9B59B6'),
('CIA', 'F39C12'),
('1/1 GT', '1ABC9C'),
('2/1 GT', '1ABC9C'),
('3/1 GT', '1ABC9C'),
('4/1 GT', '1ABC9C'),
('5/1 GT', '1ABC9C'),
('DECEA', 'DC4C64'),
('DIRAP', 'E4A11B'),
('COMGEP', '54B4D3'),
('FAB', '0099CC'),
('Portaria', 'E4A11B'),
('BOLTIMO', 'E67E22'),
('Aviões', '27AE60'),
('Helicóptero', '8E44AD'),
('Manutenção', '16A085'),
('Operações', 'D35400'),
('Instrutor', '2E86C1');

-- Exemplo de efetivo (adicione seus militares aqui)
INSERT INTO efetivo (saram, nome_guerra, nome_completo, posto, especialidade, email, om_origem, ativo, oculto) VALUES
('12345678', 'SILVA', 'JOÃO DA SILVA', 'Tenente', 'AVIADOR', 'silva@fab.mil.br', 'GAC-PAC', 1, 0),
('23456789', 'SANTOS', 'PEDRO SANTOS', 'Capitão', 'AVIADOR', 'santos@fab.mil.br', 'GAC-PAC', 1, 0),
('34567890', 'OLIVEIRA', 'JOSÉ DE OLIVEIRA', 'Sargento', 'MECÂNICO', 'oliveira@fab.mil.br', 'GAC-PAC', 1, 0),
('45678901', 'COSTA', 'MARIA DA COSTA', 'Tenente', 'ADMINISTRATIVO', 'costa@fab.mil.br', 'GAC-PAC', 1, 0),
('56789012', 'FERREIRA', 'ANTÔNIO FERREIRA', 'Capitão', 'OPERACIONAL', 'ferreira@fab.mil.br', 'COPAC', 1, 1),
('67890123', 'ALMEIDA', 'LUCAS ALMEIDA', 'Major', 'COMANDANTE', 'almeida@fab.mil.br', 'GAC-PAC', 1, 0);
