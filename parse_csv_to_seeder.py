import csv
import json

# Read the CSV file
csv_file = r'C:\Users\shubh\Downloads\Shoot History (1).csv'

# Dictionary to store unique client data
clients = {}

with open(csv_file, 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        email = row['Client Email'].strip().lower()
        if email and email not in clients:
            clients[email] = {
                'email': row['Client Email'].strip(),
                'phone': row['Client Phone'].strip(),
                'company': row['Client Company'].strip(),
                'created_by': row['User Account Created By'].strip()
            }

# Generate PHP array format
output = []
for email, data in clients.items():
    output.append({
        'email': data['email'],
        'phone': data['phone'],
        'company': data['company'],
        'created_by': data['created_by']
    })

# Print in JSON format for easier PHP conversion
print(json.dumps(output, indent=4))

