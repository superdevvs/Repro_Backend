import csv
import json
import sys

def parse_csv():
    # Read the CSV file
    csv_path = r'c:\Users\shubh\Downloads\Shoot History (2).csv'
    
    clients = {}
    shoots = []
    
    with open(csv_path, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        
        for row in reader:
            client_email = row.get('Client Email', '').strip().lower()
            
            if not client_email:
                continue
            
            # Store unique client info
            if client_email not in clients:
                clients[client_email] = {
                    'email': client_email,
                    'name': row.get('Client', '').strip(),
                    'phone': row.get('Client Phone', '').strip(),
                    'company': row.get('Client Company', '').strip(),
                    'created_by': row.get('User Account Created By', '').strip()
                }
            
            # Store shoot info
            shoots.append({
                'client_email': client_email,
                'scheduled_date': row.get('Scheduled', '').strip(),
                'completed_date': row.get('Completed', '').strip(),
                'photographer': row.get('Photographer', '').strip(),
                'services': row.get('Services', '').strip(),
                'address': row.get('Address', '').strip(),
                'city': row.get('City', '').strip(),
                'state': row.get('State', '').strip(),
                'zip': row.get('Zip', '').strip(),
                'base_quote': row.get('Base Quote', '').replace('$', '').replace(',', '').strip() or '0',
                'tax_rate': row.get('Tax', '').replace('%', '').strip() or '0',
                'tax_amount': row.get('Tax Amount', '').replace('$', '').replace(',', '').strip() or '0',
                'total_quote': row.get('Total Quote', '').replace('$', '').replace(',', '').strip() or '0',
                'total_paid': row.get('Total Paid', '').replace('$', '').replace(',', '').strip() or '0',
                'last_payment_date': row.get('Last Payment Date', '').strip(),
                'last_payment_type': row.get('Last Payment Type', '').strip(),
                'tour_purchased': row.get('Tour Purchased', '').strip(),
                'shoot_notes': row.get('Shoot Notes', '').strip(),
                'photographer_notes': row.get('Photographer Notes', '').strip(),
                'created_by': row.get('User Account Created By', '').strip()
            })
    
    # Output results
    print(json.dumps({
        'clients': list(clients.values()),
        'shoots': shoots
    }, indent=2, ensure_ascii=False))

if __name__ == '__main__':
    parse_csv()

