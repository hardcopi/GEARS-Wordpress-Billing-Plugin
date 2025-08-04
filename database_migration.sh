#!/bin/bash

# Database Migration Script
# This script connects to a remote Ubuntu server, backs up specified databases,
# and restores them to the local server

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_header() {
    echo -e "${PURPLE}================================${NC}"
    echo -e "${WHITE}$1${NC}"
    echo -e "${PURPLE}================================${NC}"
}

# Function to show progress spinner
show_spinner() {
    local pid=$1
    local delay=0.1
    local spinstr='|/-\'
    while [ "$(ps a | awk '{print $1}' | grep $pid)" ]; do
        local temp=${spinstr#?}
        printf " [%c]  " "$spinstr"
        local spinstr=$temp${spinstr%"$temp"}
        sleep $delay
        printf "\b\b\b\b\b\b"
    done
    printf "    \b\b\b\b"
}

# Function to validate input
validate_input() {
    if [ -z "$1" ]; then
        print_error "Input cannot be empty"
        return 1
    fi
    return 0
}

# Function to test SSH connection
test_ssh_connection() {
    print_status "Testing SSH connection to $REMOTE_HOST..."
    if ssh -o ConnectTimeout=10 -o BatchMode=yes $REMOTE_USER@$REMOTE_HOST exit 2>/dev/null; then
        print_success "SSH connection successful"
        return 0
    else
        print_error "SSH connection failed"
        print_warning "Please ensure:"
        echo "  - The remote host is accessible"
        echo "  - SSH keys are properly configured"
        echo "  - The username is correct"
        return 1
    fi
}

# Function to backup database on remote server
backup_remote_database() {
    local db_name=$1
    local backup_file="${db_name}_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    print_status "Backing up database '$db_name' from remote server..."
    
    # Create backup on remote server
    ssh $REMOTE_USER@$REMOTE_HOST "mysqldump -u$REMOTE_DB_USER -p$REMOTE_DB_PASS $db_name > /tmp/$backup_file" 2>/dev/null &
    local ssh_pid=$!
    show_spinner $ssh_pid
    wait $ssh_pid
    
    if [ $? -eq 0 ]; then
        print_success "Database '$db_name' backed up successfully on remote server"
        
        # Download backup file
        print_status "Downloading backup file..."
        scp $REMOTE_USER@$REMOTE_HOST:/tmp/$backup_file ./backups/ &
        local scp_pid=$!
        show_spinner $scp_pid
        wait $scp_pid
        
        if [ $? -eq 0 ]; then
            print_success "Backup file downloaded: ./backups/$backup_file"
            
            # Clean up remote backup file
            ssh $REMOTE_USER@$REMOTE_HOST "rm /tmp/$backup_file"
            echo "$backup_file"
            return 0
        else
            print_error "Failed to download backup file"
            return 1
        fi
    else
        print_error "Failed to backup database '$db_name'"
        return 1
    fi
}

# Function to restore database on local server
restore_local_database() {
    local db_name=$1
    local backup_file=$2
    
    print_status "Restoring database '$db_name' on local server..."
    
    # Drop existing database if it exists (with confirmation)
    read -p "$(echo -e ${YELLOW}[WARNING]${NC} This will drop the existing '$db_name' database. Continue? [y/N]: )" -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        mysql -u$LOCAL_DB_USER -p$LOCAL_DB_PASS -e "DROP DATABASE IF EXISTS $db_name;" 2>/dev/null
        mysql -u$LOCAL_DB_USER -p$LOCAL_DB_PASS -e "CREATE DATABASE $db_name;" 2>/dev/null
        
        # Restore database
        mysql -u$LOCAL_DB_USER -p$LOCAL_DB_PASS $db_name < ./backups/$backup_file &
        local mysql_pid=$!
        show_spinner $mysql_pid
        wait $mysql_pid
        
        if [ $? -eq 0 ]; then
            print_success "Database '$db_name' restored successfully"
            return 0
        else
            print_error "Failed to restore database '$db_name'"
            return 1
        fi
    else
        print_warning "Skipping restore for database '$db_name'"
        return 1
    fi
}

# Main function
main() {
    clear
    print_header "DATABASE MIGRATION SCRIPT"
    echo -e "${CYAN}This script will backup databases from a remote server and restore them locally${NC}"
    echo
    
    # Create backups directory if it doesn't exist
    mkdir -p ./backups
    
    # Get remote server details
    print_status "Please provide remote server details:"
    read -p "Remote host/IP: " REMOTE_HOST
    validate_input "$REMOTE_HOST" || exit 1
    
    read -p "Remote username: " REMOTE_USER
    validate_input "$REMOTE_USER" || exit 1
    
    read -s -p "Remote MySQL username: " REMOTE_DB_USER
    echo
    validate_input "$REMOTE_DB_USER" || exit 1
    
    read -s -p "Remote MySQL password: " REMOTE_DB_PASS
    echo
    validate_input "$REMOTE_DB_PASS" || exit 1
    
    # Get local database details
    print_status "Please provide local database details:"
    read -p "Local MySQL username: " LOCAL_DB_USER
    validate_input "$LOCAL_DB_USER" || exit 1
    
    read -s -p "Local MySQL password: " LOCAL_DB_PASS
    echo
    validate_input "$LOCAL_DB_PASS" || exit 1
    
    echo
    
    # Test SSH connection
    test_ssh_connection || exit 1
    
    echo
    
    # Define databases to migrate
    DATABASES=("mileage" "staff_directory" "wordpress")
    
    print_header "STARTING DATABASE MIGRATION"
    
    # Backup and restore each database
    for db in "${DATABASES[@]}"; do
        echo
        print_header "PROCESSING DATABASE: $db"
        
        backup_file=$(backup_remote_database "$db")
        if [ $? -eq 0 ] && [ ! -z "$backup_file" ]; then
            restore_local_database "$db" "$backup_file"
            if [ $? -eq 0 ]; then
                print_success "Migration completed for database '$db'"
            else
                print_error "Migration failed for database '$db'"
            fi
        else
            print_error "Backup failed for database '$db', skipping restore"
        fi
        
        echo -e "${CYAN}Press any key to continue...${NC}"
        read -n 1 -s
    done
    
    echo
    print_header "MIGRATION COMPLETE"
    print_success "All database migrations have been processed"
    
    # Show backup files
    echo
    print_status "Backup files created:"
    ls -la ./backups/*.sql 2>/dev/null || print_warning "No backup files found"
    
    echo
    print_status "Migration script finished"
}

# Error handling
set -e
trap 'print_error "Script interrupted or failed"; exit 1' ERR

# Check if required commands are available
command -v ssh >/dev/null 2>&1 || { print_error "SSH is required but not installed. Aborting."; exit 1; }
command -v scp >/dev/null 2>&1 || { print_error "SCP is required but not installed. Aborting."; exit 1; }
command -v mysql >/dev/null 2>&1 || { print_error "MySQL client is required but not installed. Aborting."; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { print_error "mysqldump is required but not installed. Aborting."; exit 1; }

# Run main function
main

exit 0
