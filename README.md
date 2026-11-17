 Valaichchenai DayTrail

Valaichchenai DayTrail is a web application for planning a one-day visit around 
Valaichenai, Pasikudah and Kalkudah. It brings local place information together 
so visitors can choose where to go and organise their day.

The project covers places within a 25 km straight-line radius of the configured 
home location. Visitors can select places themselves or get suggestions based on 
their interests, available time and travel companions.

Technologies

 HTML, CSS and JavaScript for the interface
 PHP for server-side functions
 MySQL for the database
 MySQL Workbench for database management
 Apache through XAMPP for running PHP locally
 Leaflet and OpenStreetMap for maps
 Google Maps links for directions

MySQL Workbench connects to the database server. MySQL Server must be running 
for the application to access its data. No Node.js build is needed.

 Main features

Visitors can:

 Browse places and search or filter them by category.
 Read place details, travel tips and available visitor information.
 View map locations and open directions.
 Choose several places and create a one-day plan.
 Set a start time, available hours and travel pace.
 View the visit order, estimated travel time and finishing time.
 Check opening-time warnings when opening hours are recorded.
 Register, sign in and save plans to their account.
 Download place details as text or print a generated plan.

Administrators can add, edit, deactivate or delete places. They can also manage 
categories, mood tags, photos and visitor information.

 Running the project on Windows

These steps use a separate MySQL Server connection managed through MySQL Workbench.

1. Extract the project and place the daytrail folder inside 
   C:\xampp\htdocs. Check that index.php is at 
   C:\xampp\htdocs\daytrail\index.php.
2. Start Apache in the XAMPP Control Panel. Make sure your MySQL Server service 
   is also running.
3. Open your server connection in MySQL Workbench.
4. Select File → Open SQL Script and open database/daytrail.sql from the 
   project folder. Execute the complete script and refresh the Schemas list. 
   The daytrail database should appear.
5. Copy config/config.example.php to config/config.php.
6. In config.php, enter the database host, port, username and password used by 
   your Workbench connection. Set the database name to daytrail.
7. Enter your actual home coordinates in home_lat and home_lng. Update 
   home_label and set home_verified to true only after checking the 
   location.
8. Open http://localhost/daytrail/setup.php in your browser. Create an 
   administrator account with your own email and a password of 10–72 characters.
9. Open http://localhost/daytrail/ to use the application.

If Apache uses port 8080, include :8080 in both browser addresses.

You can edit the project in VS Code, but Live Server alone cannot run PHP. 
Keep Apache and MySQL Server running while using the application.

 How the planner works

The planner starts at the configured home point and chooses the nearest remaining 
place at each step. This is a simple route-ordering method, not a guarantee of 
the shortest road route.

Straight-line distances are calculated using the Haversine formula. The 
application multiplies these distances by 1.3 to estimate road travel and uses 
an assumed speed of 30 km/h. Travel pace changes the visit duration. The plan 
ends at the last selected place and does not include a return journey home.

Distances and times are estimates. Actual travel may take longer or shorter.

 Main files

| File or folder | Purpose |
| --- | --- |
| index.php | Main pages and request handling |
| app/bootstrap.php | Database connection, sessions and shared functions |
| app/actions.php | Login, registration, place updates and saved-plan actions |
| app/planner.php | Route ordering and time calculations |
| app/views.php | Shared page components |
| app/admin.php | Administrator interface |
| assets/ | Styles, JavaScript, images and map library |
| database/daytrail.sql | Database structure and sample records |
| setup.php | Initial administrator account setup |

 Data still to be checked

The database includes ten proposal-based sample places. Their coordinates and 
visitor details are not yet fully verified. Unknown opening hours have been 
left blank.

Before submission, confirm the actual home location, all place pins, opening 
hours, entry conditions, facilities and access permissions. Seven places still 
need genuine photos. The three included Wikimedia photos are historical images 
and do not show current conditions in every case.

 Scope

The application does not provide booking, payment, hotel reservation or transport 
reservation services. It is intended for local demonstration. Maps and external 
directions need internet access.