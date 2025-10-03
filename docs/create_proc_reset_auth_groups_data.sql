DELIMITER //

CREATE PROCEDURE ResetAuthGroupsData()
BEGIN
    -- Suppression des données dans l'ordre des dépendances (enfants vers parents)
    -- Tables de relations impliquant des données d'authentification/groupes
    DELETE FROM group_invitations;
    DELETE FROM group_members;
    DELETE FROM group_tag_relations;
    DELETE FROM file_tag_relations;
    DELETE FROM valid_tokens;
    
    -- Tables principales du module authentification/groupes
    DELETE FROM files;
    DELETE FROM tags;
    DELETE FROM groups;
    DELETE FROM users;

    -- ===== DONNÉES DE TEST - MODULE AUTHENTIFICATION/GROUPES =====
    
    -- Utilisateurs
    -- password = Qwerty123456
    INSERT INTO users (id, name, email, password_hash, role, profile_image, bio, phone, date_of_birth, location, email_verified, last_login, created_at, deleted_at, updated_at) VALUES
    (1, 'Alice Admin', 'admin@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'ADMINISTRATEUR', '/uploads/avatars/1.jpg', 'Super admin', '0600000001', '1980-01-01', 'Paris',  1, NOW(), NOW(), NULL, NOW()),
    (2, 'Jean Dupont', 'user@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/2.jpg', 'Historien passionné', '0600000002', '1990-02-15', 'Lyon',  1, NOW(), NOW(), NULL, NOW()),
    (3, 'Marie Curie', 'marie.curie@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/3.jpg', 'Scientifique', '0600000003', '1985-03-10', 'Varsovie',  1, NULL, NOW(), NULL, NOW()),
    (4, 'Paul Valéry', 'paul.valery@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/4.jpg', 'Poète', '0600000004', '1992-04-20', 'Sète',  1, NULL, NOW(), NULL, NOW()),
    (5, 'Lucie Martin', 'lucie.martin@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/5.jpg', '', '0600000005', '1995-05-25', 'Marseille',  1, NOW(), NOW(), NULL, NOW()),
    (6, 'Emma Leroy', 'emma.leroy@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/6.jpg', '', '0600000006', '1993-06-30', 'Bordeaux',  1, NULL, NOW(), NULL, NOW()),
    (7, 'Léo Garnier', 'leo.garnier@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/7.jpg', '', '0600000007', '1988-07-12', 'Nantes',  1, NOW(), NOW(), NULL, NOW()),
    (8, 'Julien Bernard', 'julien.bernard@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/8.jpg', '', '0600000008', '1991-08-18', 'Toulouse',  1, NULL, NOW(), NULL, NOW()),
    (9, 'Sophie Durand', 'sophie.durand@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/9.jpg', '', '0600000009', '1994-09-22', 'Lille',  1, NOW(), NOW(), NULL, NOW()),
    (10, 'Thomas Roux', 'thomas.roux@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'UTILISATEUR', '/uploads/avatars/10.jpg', '', '0600000010', '1987-10-05', 'Nice',  0, NULL, NOW(), NULL, NOW());

    -- Groupes
    INSERT INTO groups (id, name, owner_id, max_members, visibility, created_at) VALUES
    (1, 'Historiens de Paris', 1, 100, 'public', NOW()),
    (2, 'Explorateurs', 2, 50, 'private', NOW());

    -- Membres de groupes
    INSERT INTO group_members (group_id, user_id, invited_by, role, joined_at, created_at) VALUES
    (1, 2, 1, 'admin', NOW(), NOW()),
    (2, 2, 2, 'admin', NOW(), NOW()),
    (1, 3, 1, 'member', NOW(), NOW()),
    (2, 5, 2, 'member', NOW(), NOW()),
    (2, 6, 2, 'member', NOW(), NOW());

    -- Tags
    INSERT INTO tags (id, name, tag_owner, table_associate, color, created_at) VALUES
    (1, 'Histoire', 1, 'memories', '#3498db', NOW()),
    (2, 'Voyage', 1, 'memories', '#e67e22', NOW()),
    (3, 'Paris', 2, 'memories', '#2ecc71', NOW());

    -- Fichiers
    INSERT INTO files (id, file_path, file_name, file_size, mime_type, media_type, description, uploaded_by, uploaded_at) VALUES
    (1, '/uploads/memories/1/photo1.jpg', 'photo1.jpg', 204800, 'image/jpeg', 'image', 'Photo de soldats', 2, NOW()),
    (2, '/uploads/memories/2/audio1.mp3', 'audio1.mp3', 512000, 'audio/mpeg', 'audio', 'Enregistrement Amazonie', 5, NOW()),
    (3, '/uploads/memories/3/doc1.pdf', 'doc1.pdf', 102400, 'application/pdf', 'document', 'Guide de Lyon', 3, NOW());

    -- Invitations de groupe
    INSERT INTO group_invitations (group_id, invited_email, invited_by, invitation_token, status, expires_at, created_at) VALUES
    (1, 'invite1@cmem1.com', 1, 'TOKEN1', 'pending', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW()),
    (1, 'invite2@cmem1.com', 2, 'TOKEN2', 'accepted', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW()),
    (2, 'invite3@cmem1.com', 5, 'TOKEN3', 'declined', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW());

    -- Relations groupe-tag
    INSERT INTO group_tag_relations (group_id, tag_id, created_at) VALUES
    (1, 1, NOW()),
    (1, 2, NOW()),
    (2, 2, NOW()),
    (2, 3, NOW());

    -- Relations fichier-tag
    INSERT INTO file_tag_relations (file_id, tag_id, created_at) VALUES
    (1, 1, NOW()),
    (1, 2, NOW()),
    (2, 2, NOW()),
    (3, 3, NOW());

    SELECT 'Données du module authentification/groupes réinitialisées avec succès' as message, NOW() as reset_at;
END //

DELIMITER ;