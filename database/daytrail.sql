CREATE DATABASE IF NOT EXISTS daytrail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE daytrail;
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL,
 role ENUM('visitor','admin') NOT NULL DEFAULT 'visitor', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS category(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_name VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS tourist_place(
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL, description TEXT NOT NULL, address VARCHAR(255) NOT NULL,
 latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL,
 open_time TIME NULL, close_time TIME NULL, visit_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
 mood_tags VARCHAR(200) NOT NULL DEFAULT 'Nature', travel_tips TEXT NOT NULL,
 image VARCHAR(1000) NOT NULL DEFAULT '', image_credit VARCHAR(1000) NOT NULL DEFAULT '',
 entry_info VARCHAR(255) NOT NULL DEFAULT '', contact VARCHAR(100) NOT NULL DEFAULT '',
 dress_code TEXT NOT NULL, footwear TEXT NOT NULL, photography TEXT NOT NULL,
 facilities TEXT NOT NULL, best_time VARCHAR(150) NOT NULL DEFAULT '',
 hidden_gem BOOLEAN NOT NULL DEFAULT 0, kid_friendly BOOLEAN NOT NULL DEFAULT 0,
 wheelchair BOOLEAN NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT 1,
 verified BOOLEAN NOT NULL DEFAULT 0, source_url VARCHAR(1000) NOT NULL DEFAULT '',
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(category_id) REFERENCES category(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS itineraries(
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
 title VARCHAR(100) NOT NULL, plan_json LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS visit_plan(
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,itinerary_id INT UNSIGNED NOT NULL,
 place_id INT UNSIGNED NULL,visit_order SMALLINT NOT NULL,
 FOREIGN KEY(itinerary_id) REFERENCES itineraries(id) ON DELETE CASCADE,
 FOREIGN KEY(place_id) REFERENCES tourist_place(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts(
 key_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, last_attempt BIGINT NOT NULL
) ENGINE=InnoDB;

 
INSERT IGNORE INTO category(id,category_name) VALUES (1,'Beach');
INSERT IGNORE INTO category(id,category_name) VALUES (2,'Nature/Park');
INSERT IGNORE INTO category(id,category_name) VALUES (3,'Culture');
INSERT IGNORE INTO category(id,category_name) VALUES (4,'Local Culture / Recreation');
INSERT IGNORE INTO category(id,category_name) VALUES (5,'Monument/Religious');
INSERT IGNORE INTO category(id,category_name) VALUES (6,'Landmark / Recreation');
INSERT IGNORE INTO category(id,category_name) VALUES (7,'Industrial Heritage');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (1,1,'Pasikudah Beach','A coastal beach in the Pasikudah area, included in the project proposal for a relaxed seaside stop.','Pasikudah',7.9297,81.5612,90,'Nature,Adventure','Check sea conditions locally; supervise children and do not assume swimming is safe.','assets/photos/pasikudah-beach.jpg','Anton Croos / Wikimedia Commons / CC BY-SA 4.0','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Morning or late afternoon',0,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (2,1,'Passikudah Elephant Stones','A rocky coastal spot listed in the proposal for quiet sea views. Confirm the exact access point before travel.','Pasikudah coast',7.935,81.567,40,'Nature,Adventure','Rocks can be slippery. Avoid rough seas and check public access.','','','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Early morning',1,0,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (3,2,'Sunrise View Point','The proposal describes a viewpoint between the Pasikudah and Kalkudah bays. Exact pin requires local verification.','Pasikudah / Kalkudah',7.92,81.574,30,'Nature','Check the approach in daylight before planning an early visit.','','','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Sunrise',1,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (4,3,'Coconut Cultural Park','A coconut-themed cultural stop proposed for learning about local produce and crafts. Confirm current operations.','Pasikudah area',7.93,81.555,60,'Food & Culture,Nature','Ask about opening hours, activities and any entry charge before visiting.','','','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Confirm with operator',1,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (5,4,'Valaichenai Fishing Harbour','A working fishing harbour included for observing the local fishing community from permitted public areas.','Valaichenai',7.923,81.534,45,'Food & Culture,History','Do not enter working areas without permission. Ask before photographing people.','','','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Morning; confirm access',1,0,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (6,5,'Pethazhi Shiva Temple','A Hindu religious site in the proposal. Confirm the exact temple location and visiting arrangements locally.','Pethazhi',7.916,81.55,40,'History,Food & Culture','Respect worshippers and request permission before taking photographs.','','','Not verified; confirm before visiting','','Dress respectfully; follow local temple rules.','Follow footwear instructions at the entrance.','Ask permission before photographing people or worship.','Not verified','Outside worship services; confirm',0,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (7,5,'Sri Munai Murukan Kovil','A coastal Hindu temple listed in the proposal near the sunrise-view area. Coordinates need local verification.','Kalkudah / Pasikudah area',7.923,81.57,40,'History,Food & Culture','Follow temple guidance and avoid interrupting worship.','','','Not verified; confirm before visiting','','Dress respectfully; follow local temple rules.','Follow footwear instructions at the entrance.','Ask permission before photographing people or worship.','Not verified','Confirm temple visiting times',1,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (8,6,'Oddamavadi Bridge','A local bridge and lagoon landmark included for a short scenic stop from an appropriate public viewpoint.','Oddamavadi',7.909,81.521,25,'Nature,History','Do not stop on the carriageway. Use a safe permitted viewpoint away from traffic.','assets/photos/oddamavadi-bridge.jpg','Anton Croos / Wikimedia Commons / CC BY-SA 4.0','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Late afternoon',0,1,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (9,7,'Valaichenai NPC Paper Mill','An industrial heritage landmark linked to the local paper industry. This is not an invitation to enter the factory.','Valaichenai',7.931,81.524,45,'History','Operating industrial site: obtain permission; otherwise view only from permitted public areas.','assets/photos/valaichchenai-paper-mill.jpg','L Manju / Wikimedia Commons / CC BY-SA 4.0; historic photo, 2015','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Only with prior permission',1,0,0,1,0,'');
INSERT IGNORE INTO tourist_place(id,category_id,name,description,address,latitude,longitude,visit_minutes,mood_tags,travel_tips,image,image_credit,entry_info,contact,dress_code,footwear,photography,facilities,best_time,hidden_gem,kid_friendly,wheelchair,active,verified,source_url) VALUES (10,2,'Eravur Lagoon Park','A lagoon-side park stop proposed for scenery and relaxation. Verify the park entrance and available facilities.','Eravur',7.779,81.603,60,'Nature,Food & Culture','Keep away from unprotected water edges. Confirm current park access.','','','Not verified; confirm before visiting','','','','Ask permission before photographing people or worship.','Not verified','Late afternoon',0,1,0,1,0,'');
