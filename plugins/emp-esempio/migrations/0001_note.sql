-- {prefix} = prefisso installazione + "esempio_"
CREATE TABLE IF NOT EXISTS {prefix}note (
    id CHAR(36) NOT NULL PRIMARY KEY,
    testo VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(191) NOT NULL
);
