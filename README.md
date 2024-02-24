# Siikr

IYKYK.

# Installation

Best done on a clean Ubuntu or debian server.

0. clone this repo.
1. Copy `siikr.example.env` to `siikr.env` and fill out (at a minimum) the first three lines.
2. If not already in a terminal, switch to one and run `chmod +x setup_simple.sh`
3. Then run `sudo ./setup_simple.sh` and follow the prompts.
4. Pray.
5. Submit an issue if it fails.

# Docker installation

Docker gives you a clean server, but the install may be a little more involved.

0. clone this repo.
1. Make sure you have both Docker and `docker-compose` installed.
2. Copy `siikr.example.env` to `siikr.env` and fill out (at a minimum) the first three lines.
3. Run `docker-compose build`
4. Create the database using `docker/create_database.sh`
5. Bring the service up using `docker-compose up -d`
6. Optionally, check logs using `docker-compose logs -f`

