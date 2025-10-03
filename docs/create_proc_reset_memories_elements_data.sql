DELIMITER //

CREATE PROCEDURE ResetMemoriesElementsData()
BEGIN
    -- Suppression des données dans l'ordre des dépendances (enfants vers parents)
    -- Tables de relations impliquant mémoires et éléments
    DELETE FROM memory_user_relations;
    DELETE FROM memory_tag_relations;
    DELETE FROM memory_group_relations;
    DELETE FROM memory_element_relations;
    DELETE FROM element_file_relations;
    DELETE FROM element_tag_relations;
    
    -- Tables principales du module mémoires/éléments
    DELETE FROM elements;
    DELETE FROM memories;

    -- ===== DONNÉES DE TEST - MODULE MÉMOIRES/ÉLÉMENTS =====
    
    -- Mémoires
    INSERT INTO memories (id, user_id, title, content, visibility, time_start, time_end, location, latitude, longitude, created_at) VALUES
    (1, 2, 'Première Guerre Mondiale', 'Souvenirs de la guerre...', 'public', '1914-07-28 00:00:00', '1918-11-11 00:00:00', 'Paris', 48.8566, 2.3522, NOW()),
    (2, 5, 'Voyage en Amazonie', 'Expédition en forêt tropicale', 'private', '2023-05-01 00:00:00', '2023-05-15 00:00:00', 'Amazonie', -3.4653, -62.2159, NOW()),
    (3, 3, 'Découverte de Lyon', 'Balade historique à Lyon', 'public', '2024-04-10 00:00:00', '2024-04-10 00:00:00', 'Lyon', 45.7640, 4.8357, NOW());

    -- Éléments
    INSERT INTO elements (id, title, owner_id, content, media_type, visibility, created_at) VALUES
    (1, 'Lettre ancienne', 2, 'Lettre retrouvée dans le grenier', 'text', 'public', NOW()),
    (2, 'Photo de soldats', 2, '/uploads/memories/1/photo1.jpg', 'image', 'public', NOW()),
    (3, 'Enregistrement Amazonie', 5, '/uploads/memories/2/audio1.mp3', 'audio', 'private', NOW()),
    (4, 'Guide de Lyon', 3, '/uploads/memories/3/doc1.pdf', 'document', 'public', NOW());

    -- Relations mémoire-élément
    INSERT INTO memory_element_relations (memory_id, element_id, created_at) VALUES
    (1, 1, NOW()),
    (1, 2, NOW()),
    (2, 3, NOW()),
    (3, 4, NOW());

    -- Relations élément-fichier
    INSERT INTO element_file_relations (element_id, file_id, created_at) VALUES
    (2, 1, NOW()),
    (3, 2, NOW()),
    (4, 3, NOW());

    -- Relations mémoire-tag
    INSERT INTO memory_tag_relations (memory_id, tag_id) VALUES
    (1, 1),
    (2, 2),
    (3, 3);

    -- Relations élément-tag
    INSERT INTO element_tag_relations (element_id, tag_id) VALUES
    (1, 1),
    (2, 1),
    (3, 2),
    (4, 3);

    -- Relations mémoire-groupe
    INSERT INTO memory_group_relations (memory_id, group_id, created_at) VALUES
    (1, 1, NOW()),
    (2, 2, NOW()),
    (3, 1, NOW());

    -- Relations mémoire-utilisateur (accès partagé)
    INSERT INTO memory_user_relations (memory_id, user_id, created_at) VALUES
    (1, 2, NOW()), -- créateur
    (1, 3, NOW()),
    (1, 4, NOW()),
    (2, 5, NOW()), -- créateur
    (2, 6, NOW()),
    (3, 3, NOW()), -- créateur
    (3, 7, NOW());

    -- ===== DONNÉES DE TEST ÉTENDUES POUR LES MÉMOIRES =====
    
    -- Mémoires avec différentes visibilités pour tester tous les cas
    INSERT INTO memories (id, user_id, title, content, visibility, time_start, time_end, location, latitude, longitude, created_at) VALUES
    -- Mémoires publiques (visibles par tous)
    (20, 2, 'Histoire de Paris - Test Publique', 'Une mémoire publique pour les tests', 'public', '2020-01-01 10:00:00', '2020-01-01 18:00:00', 'Paris, France', 48.8566, 2.3522, NOW()),
    (21, 3, 'Sciences à Lyon - Test Publique', 'Découvertes scientifiques pour tests', 'public', '2021-05-15 09:00:00', '2021-05-15 17:00:00', 'Lyon, France', 45.7640, 4.8357, NOW()),
    (22, 5, 'Art Moderne - Test Publique', "Exposition d'art moderne pour tests", 'public', '2022-03-10 14:00:00', '2022-03-10 20:00:00', 'Marseille, France', 43.2965, 5.3698, NOW()),

    -- Mémoires privées (visibles uniquement par le créateur)
    (23, 2, 'Journal Personnel - Test Privé', 'Mes pensées personnelles de test', 'private', '2023-01-15 20:00:00', '2023-01-15 22:00:00', 'Paris, France', 48.8566, 2.3522, NOW()),
    (24, 3, 'Notes de Recherche - Test Privé', 'Mes notes de recherche de test', 'private', '2023-02-20 08:00:00', '2023-02-20 12:00:00', 'Lyon, France', 45.7640, 4.8357, NOW()),
    (25, 5, 'Projet Secret - Test Privé', 'Mon projet secret de test', 'private', '2023-03-25 16:00:00', '2023-03-25 18:00:00', 'Marseille, France', 43.2965, 5.3698, NOW()),

    -- Mémoires partagées (visibles par certains utilisateurs via relations)
    (26, 2, 'Projet Équipe A - Test Partagé', 'Mémoire partagée pour tests', 'shared', '2023-04-01 09:00:00', '2023-04-01 17:00:00', 'Paris, France', 48.8566, 2.3522, NOW()),
    (27, 3, 'Recherche Collaborative - Test Partagé', 'Recherche partagée pour tests', 'shared', '2023-04-15 10:00:00', '2023-04-15 16:00:00', 'Lyon, France', 45.7640, 4.8357, NOW()),
    (28, 5, 'Voyage Groupe - Test Partagé', 'Souvenirs de voyage pour tests', 'shared', '2023-05-01 08:00:00', '2023-05-03 20:00:00', 'Nice, France', 43.7102, 7.2620, NOW()),

    -- Mémoires supplémentaires pour les tests de pagination
    (29, 4, 'Littérature Française - Test Publique', 'Analyse littéraire pour tests', 'public', '2023-06-01 14:00:00', '2023-06-01 18:00:00', 'Sète, France', 43.4057, 3.6943, NOW()),
    (30, 6, 'Histoire Locale - Test Publique', 'Histoire de Bordeaux pour tests', 'public', '2023-06-15 10:00:00', '2023-06-15 16:00:00', 'Bordeaux, France', 44.8378, -0.5792, NOW()),
    (31, 7, 'Culture Bretonne - Test Publique', 'Traditions bretonnes pour tests', 'public', '2023-07-01 09:00:00', '2023-07-01 17:00:00', 'Nantes, France', 47.2184, -1.5536, NOW());

    -- Relations memory_user_relations pour les mémoires partagées étendues
    INSERT IGNORE INTO memory_user_relations (memory_id, user_id, created_at) VALUES
    -- Mémoire 26 (Projet Équipe A) - créée par user_id=2, partagée avec 3, 4, 5
    (26, 3, NOW()),
    (26, 4, NOW()),
    (26, 5, NOW()),

    -- Mémoire 27 (Recherche Collaborative) - créée par user_id=3, partagée avec 2, 5, 6
    (27, 2, NOW()),
    (27, 5, NOW()),
    (27, 6, NOW()),

    -- Mémoire 28 (Voyage Groupe) - créée par user_id=5, partagée avec 2, 3, 7
    (28, 2, NOW()),
    (28, 3, NOW()),
    (28, 7, NOW());

    -- Relations memory_tag_relations pour catégoriser les mémoires étendues
    INSERT INTO memory_tag_relations (memory_id, tag_id) VALUES
    (20, 1), -- Histoire de Paris -> Histoire
    (20, 3), -- Histoire de Paris -> Paris
    (21, 1), -- Sciences à Lyon -> Histoire
    (22, 2), -- Art Moderne -> Voyage
    (26, 1), -- Projet Équipe A -> Histoire
    (27, 1), -- Recherche Collaborative -> Histoire
    (28, 2), -- Voyage Groupe -> Voyage
    (29, 1), -- Littérature Française -> Histoire
    (30, 1), -- Histoire Locale -> Histoire
    (31, 2); -- Culture Bretonne -> Voyage

    -- Relations memory_group_relations pour associer mémoires aux groupes étendues
    INSERT INTO memory_group_relations (memory_id, group_id, created_at) VALUES
    (20, 1, NOW()), -- Histoire de Paris -> Historiens de Paris
    (21, 1, NOW()), -- Sciences à Lyon -> Historiens de Paris
    (26, 1, NOW()), -- Projet Équipe A -> Historiens de Paris
    (28, 2, NOW()), -- Voyage Groupe -> Explorateurs
    (29, 1, NOW()), -- Littérature Française -> Historiens de Paris
    (30, 1, NOW()), -- Histoire Locale -> Historiens de Paris
    (31, 2, NOW()); -- Culture Bretonne -> Explorateurs

    -- Relations élément-tag supplémentaires
    INSERT INTO element_tag_relations (element_id, tag_id) VALUES
    (1, 2),
    (2, 3),
    (3, 1),
    (4, 2);

    -- Relations mémoire-élément supplémentaires
    INSERT INTO memory_element_relations (memory_id, element_id, created_at) VALUES
    (1, 3, NOW()),
    (2, 4, NOW()),
    (3, 1, NOW());

    -- Relations élément-fichier supplémentaires
    INSERT INTO element_file_relations (element_id, file_id, created_at) VALUES
    (1, 1, NOW()),
    (2, 2, NOW()),
    (3, 3, NOW()),
    (4, 1, NOW());

    SELECT 'Données du module mémoires/éléments réinitialisées avec succès' as message, NOW() as reset_at;
END //

DELIMITER ;