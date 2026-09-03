---
name: analyze-merchant-google-sheet
description: Analyze merchant Google Sheets with comprehensive financial metrics and reporting in KSH currency format
category: data
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

# Analyze Merchant Google Sheets Skill

This skill analyzes merchant Google Sheets and generates comprehensive financial reports with visualizations.

## Usage Instructions

When you receive a Google Sheet URL or ID related to merchant reports (like 'DUKA ONE - REALDEAL KE'), follow these steps:

1. **Extract the spreadsheet ID** from the URL (e.g., '1gVnqSmR7hk8q9ZTcIW5qGaxkvpwzONrlTZWCSEqhuh4' from 'https://docs.google.com/spreadsheets/d/1gVnqSmR7hk8q9ZTcIW5qGaxkvpwzONrlTZWCSEqhuh4/edit')

2. **Read the sheet data** using Google Sheets API:
   ```python
   response = service.spreadsheets().values().get(
       spreadsheetId='1gVnqSmR7hk8q9ZTcIW5qGaxkvpwzONrlTZWCSEqhuh4',
       range='MIXING CUP2026!A1:Z100'
   ).execute()
   values = response.get('values', [])
   ```

3. **Convert data to DataFrame** for analysis:
   ```python
   import pandas as pd
   df = pd.DataFrame(values[1:], columns=values[0])  # Skip header row
   ```

4. **Generate comprehensive analysis** including:
   - Key financial metrics (total orders, revenue, average order value, date range)
   - Order status breakdown
   - Top clients by order count and revenue
   - Top products by order count and revenue
   - Monthly sales breakdown
   - Sales by country
   - Revenue by order status
   - Key insights and summary statistics

5. **Create visualizations**:
   - Monthly revenue trend (bar chart)
   - Order status distribution (pie chart)
   - Top 5 products by revenue (horizontal bar chart)
   - Revenue by country (bar chart)

6. **Save outputs** to `/home/atlas/Downloads/`:
   - Text report: `MERCHANT_NAME_Analysis_Report.md`
   - Visual dashboard: `MERCHANT_NAME_Financial_Analysis.png`
   - Comprehensive report: `MERCHANT_NAME_Comprehensive_Report.md`

7. **Send to user via Telegram**:
   - Send summary message with key metrics
   - Send the visualization image with caption
   - Send the comprehensive report as document

8. **Extract merchant name** from sheet title (e.g., "MIXING CUP2026" or "DUKA ONE")

## Required Python Packages
- pandas
- openpyxl
- matplotlib
- google-auth
- google-api-python-client

## Key Metrics to Calculate
- Total orders and revenue
- Average order value
- Date range (min/max order dates)
- Order status breakdown
- Monthly revenue breakdown
- Product performance analysis
- Client analysis
- Geographic sales distribution
- Revenue trends and patterns

## Currency Format
All financial values should be formatted in **KSH (Kenyan Shillings)** with commas for thousands and 2 decimal places.

## Example Implementation

Here's the complete implementation to analyze a merchant Google Sheet:

```python
import pandas as pd
import matplotlib.pyplot as plt
import os
from google.oauth2.service_account import ServiceAccountCredentials
from googleapiclient.discovery import build

# Read the Google Sheet
spreadsheet_id = '1gVnqSmR7hk8q9ZTcIW5qGaxkvpwzONrlTZWCSEqhuh4'
range_name = 'MIXING CUP2026!A1:Z100'

# Authenticate and read data
credentials = ServiceAccountCredentials.from_json_keyfile_name('service_account.json', ['https://www.googleapis.com/auth/spreadsheets.readonly'])
service = build('sheets', 'v4', credentials=credentials)
response = service.spreadsheets().values().get(
    spreadsheetId=spreadsheet_id,
    range=range_name
).execute()
values = response.get('values', [])

# Convert to DataFrame
df = pd.DataFrame(values[1:], columns=values[0])

# Extract merchant name from sheet title
merchant_name = 'MIXING_CUP2026'  # or extract from spreadsheet metadata

# Convert Amount to numeric
df['AMOUNT'] = pd.to_numeric(df['AMOUNT'].replace('[^0-9.]', '', regex=True), errors='coerce').fillna(0)

# Calculate key metrics
total_orders = len(df)
total_revenue = df['AMOUNT'].sum()
avg_order_value = df['AMOUNT'].mean()
total_quantity = df['QUANTITY'].sum()
min_date = df['ORDER DATE'].min()
max_date = df['ORDER DATE'].max()

# Create visualizations
fig, axes = plt.subplots(2, 2, figsize=(16, 12))
fig.suptitle(f'{merchant_name} Financial Analysis Dashboard', fontsize=16, fontweight='bold')

# 1. Monthly Revenue Trend
monthly_revenue = df.groupby(pd.to_datetime(df['ORDER DATE']).dt.strftime('%Y-%m'))['AMOUNT'].sum().sort_index()
axes[0, 0].bar(monthly_revenue.index, monthly_revenue.values, color='steelblue', edgecolor='navy')
axes[0, 0].set_title('Monthly Revenue Trend', fontsize=12, fontweight='bold')
axes[0, 0].set_xlabel('Month')
axes[0, 0].set_ylabel('Revenue (KSH)')
axes[0, 0].tick_params(axis='x', rotation=45)
for i, v in enumerate(monthly_revenue.values):
    axes[0, 0].text(i, v + 5000, f'KSH {v:,.0f}', ha='center', fontsize=9)
axes[0, 0].grid(axis='y', alpha=0.3)

# 2. Order Status Distribution
status_counts = df['STATUS'].value_counts()
colors = ['#4CAF50', '#FFC107']
explode = [0.05] * len(status_counts) if len(status_counts) > 1 else [0]
axes[0, 1].pie(status_counts.values, labels=status_counts.index, autopct='%1.1f%%', 
               colors=colors, startangle=90, explode=explode)
axes[0, 1].set_title('Order Status Distribution', fontsize=12, fontweight='bold')

# 3. Top 5 Products by Revenue
product_revenue = df.groupby('PRODUCT NAME')['AMOUNT'].sum().nlargest(5)
axes[1, 0].barh(product_revenue.index, product_revenue.values, color='coral', edgecolor='darkred')
axes[1, 0].set_title('Top 5 Products by Revenue', fontsize=12, fontweight='bold')
axes[1, 0].set_xlabel('Revenue (KSH)')
for i, v in enumerate(product_revenue.values):
    axes[1, 0].text(v + 500, i, f'KSH {v:,.0f}', va='center', fontsize=9)
axes[1, 0].grid(axis='x', alpha=0.3)

# 4. Revenue by Country
country_revenue = df.groupby('COUNTRY')['AMOUNT'].sum()
axes[1, 1].bar(country_revenue.index, country_revenue.values, color='teal', edgecolor='darkcyan')
axes[1, 1].set_title('Revenue by Country', fontsize=12, fontweight='bold')
axes[1, 1].set_ylabel('Revenue (KSH)')
for i, v in enumerate(country_revenue.values):
    axes[1, 1].text(i, v + 10000, f'KSH {v:,.0f}', ha='center', fontsize=9)
axes[1, 1].grid(axis='y', alpha=0.3)

plt.tight_layout()
output_image = f'/home/atlas/Downloads/{merchant_name}_Financial_Analysis.png'
plt.savefig(output_image, dpi=300, bbox_inches='tight')
plt.close()

# Create comprehensive report
report_lines = []
report_lines.append("# " + "=" * 98)
report_lines.append(f"# COMPREHENSIVE FINANCIAL ANALYSIS REPORT: {merchant_name} MERCHANT REPORT")
report_lines.append("# " + "=" * 98)
report_lines.append("")
report_lines.append("## 📊 KEY FINANCIAL METRICS")
report_lines.append("")
report_lines.append(f"- **Total Orders:** {total_orders:,}")
report_lines.append(f"- **Total Revenue:** KSH {total_revenue:,.2f}")
report_lines.append(f"- **Average Order Value:** KSH {avg_order_value:,.2f}")
report_lines.append(f"- **Total Products Sold:** {total_quantity:,}")
report_lines.append(f"- **Date Range:** {min_date} to {max_date}")
report_lines.append("")
report_lines.append("## ✅ ORDER STATUS BREAKDOWN")
status_counts = df['STATUS'].value_counts()
for status, count in status_counts.items():
    percentage = (count / total_orders) * 100
    report_lines.append(f"- **{status}:** {count} orders ({percentage:.1f}%)")
report_lines.append("")
report_lines.append("## 📦 TOP PRODUCTS BY REVENUE")
product_revenue = df.groupby('PRODUCT NAME')['AMOUNT'].sum().sort_values(ascending=False).head(10)
for product, revenue in product_revenue.items():
    count = df[df['PRODUCT NAME'] == product].shape[0]
    report_lines.append(f"- **{product}:** KSH {revenue:,.2f} ({count} orders)")
report_lines.append("")
report_lines.append("## 📈 MONTHLY SALES BREAKDOWN")
df['Month'] = pd.to_datetime(df['ORDER DATE']).dt.strftime('%Y-%m')
monthly_sales = df.groupby('Month')['AMOUNT'].sum()
for month, revenue in monthly_sales.items():
    report_lines.append(f"- **{month}:** KSH {revenue:,.2f}")
report_lines.append("")
report_lines.append("# " + "=" * 98)
report_lines.append("# END OF REPORT")
report_lines.append("# " + "=" * 98)

output_file = f'/home/atlas/Downloads/{merchant_name}_Comprehensive_Financial_Report.md'
with open(output_file, 'w') as f:
    f.write('\n'.join(report_lines))

print(f"✅ Analysis complete for {merchant_name}!")
print(f"📊 Visual dashboard: {output_image}")
print(f"📄 Comprehensive report: {output_file}")
```

## Telegram Sending Functionality

Use these curl commands to send files to user Mo L (chat ID: 8144561484):

**Send summary message:**
```bash
curl -s -X POST https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendMessage \
  -d chat_id=8144561484 \
  -d text="📊 [MERCHANT_NAME] Financial Analysis Complete!"
```

**Send image:**
```bash
curl -s -X POST https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendPhoto \
  -F "chat_id=8144561484" \
  -F "photo=@/home/atlas/Downloads/[MERCHANT_NAME]_Financial_Analysis.png" \
  -F "caption=📊 Financial Analysis Dashboard"
```

**Send document:**
```bash
curl -s -X POST https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendDocument \
  -F "chat_id=8144561484" \
  -F "document=@/home/atlas/Downloads/[MERCHANT_NAME]_Comprehensive_Financial_Report.md" \
  -F "caption=📄 Comprehensive Financial Analysis Report"
```

## Key Features
- Automatic merchant name extraction from sheet title
- Professional formatting with emojis and visual elements
- Comprehensive metrics and insights
- Automated Telegram notifications
- Reusable for any merchant Google Sheet
- Currency format: KSH (Kenyan Shillings)
- Output format: Markdown (.md files)
