import csv
import json
from datetime import datetime

# Read the CSV file
csv_file = r'C:\Users\shubh\Downloads\Shoot History (1).csv'

shoots = []

with open(csv_file, 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        # Parse dates
        scheduled = row['Scheduled'].strip()
        completed = row['Completed'].strip()
        
        # Parse amounts
        def parse_amount(value):
            if not value:
                return "0.00"
            # Remove $ and commas
            cleaned = value.replace('$', '').replace(',', '').strip()
            return cleaned if cleaned else "0.00"
        
        shoot = {
            'client_email': row['Client Email'].strip().lower(),
            'photographer_name': row['Photographer'].strip(),
            'scheduled_date': scheduled if scheduled else None,
            'completed_date': completed if completed else None,
            'address': row['Address'].strip(),
            'address2': row['Address2'].strip(),
            'city': row['City'].strip(),
            'state': row['State'].strip(),
            'zip': row['Zip'].strip(),
            'services': row['Services'].strip(),
            'base_quote': parse_amount(row['Base Quote']),
            'tax_rate': row['Tax'].strip().replace('%', ''),
            'tax_amount': parse_amount(row['Tax Amount']),
            'total_quote': parse_amount(row['Total Quote']),
            'total_paid': parse_amount(row['Total Paid']),
            'last_payment_date': row['Last Payment Date'].strip() if row['Last Payment Date'].strip() else None,
            'last_payment_type': row['Last Payment Type'].strip(),
            'tour_purchased': row['Tour Purchased'].strip(),
            'shoot_notes': row['Shoot Notes'].strip(),
            'photographer_notes': row['Photographer Notes'].strip(),
            'created_by': row['User Account Created By'].strip()
        }
        
        shoots.append(shoot)

# Output as JSON
print(json.dumps(shoots, indent=2))
print(f"\n\nTotal shoots: {len(shoots)}", file=__import__('sys').stderr)

