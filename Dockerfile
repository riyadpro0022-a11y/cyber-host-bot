# বেস ইমেজ হিসেবে PHP এবং Apache সার্ভার ব্যবহার করা হচ্ছে
FROM php:8.2-apache

# প্রজেক্টের সব ফাইল সার্ভারের পাবলিক ফোল্ডারে কপি করা
COPY . /var/www/html/

# ফাইল আপলোড এবং নতুন ফোল্ডার (bots/) তৈরির জন্য ফোল্ডার পারমিশন দেওয়া
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 777 /var/www/html/

# পোর্ট 80 ওপেন রাখা
EXPOSE 80
